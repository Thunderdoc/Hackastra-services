<?php

namespace App\Support;

use App\Models\QuestionBankQuestion;
use App\Models\QuestionPaper;

/**
 * F4 Mode A selection engine (design §3.2–§3.4).
 *
 * Deterministic, seeded, exact-marks: the same (spec, seed, bank
 * contents, exclusion set) always yields the byte-identical paper.
 * Ordering is a hash-sort — sha256(seed | spec-hash | bank_id) ASC,
 * id ASC as tiebreak — never mt_srand/shuffle, so PHP, the tests
 * and any future reimplementation agree.
 *
 * A section either resolves EXACTLY (count + marks) or returns an
 * explicit shortfall object. Nothing is ever padded, duplicated or
 * marks-fudged to make a section "work".
 */
class PaperSelection
{
    /** sha256 of the normalised question text (§3.4). */
    public static function textHash(string $text): string
    {
        $normalised = mb_strtolower($text);
        $normalised = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $normalised);
        $normalised = trim(preg_replace('/\s+/', ' ', $normalised));

        return hash('sha256', $normalised);
    }

    /** Stable hash of the selection-relevant spec + scope. */
    public static function specHash(array $spec, array $scope): string
    {
        $sections = [];
        foreach (($spec['sections'] ?? []) as $s) {
            $sections[] = [
                'key' => $s['key'] ?? '',
                'type' => $s['type'] ?? '',
                'count' => (int) ($s['count'] ?? 0),
                'marks_each' => isset($s['marks_each']) && $s['marks_each'] !== '' ? (int) $s['marks_each'] : null,
                'marks_total' => isset($s['marks_total']) && $s['marks_total'] !== '' ? (int) $s['marks_total'] : null,
                'chapters' => array_values($s['chapters'] ?? []),
                'difficulty_mix' => $s['difficulty_mix'] ?? null,
            ];
        }

        return hash('sha256', json_encode([
            'sections' => $sections,
            'my_class_id' => (int) ($scope['my_class_id'] ?? 0),
            'section_id' => isset($scope['section_id']) ? (int) $scope['section_id'] : null,
            'subject_id' => (int) ($scope['subject_id'] ?? 0),
        ]));
    }

    protected static function normaliseLabel($label): string
    {
        return trim(preg_replace('/\s+/', ' ', mb_strtolower((string) $label)));
    }

    /**
     * Final papers in the duplicate scope (§3.4) for this request.
     * Returns [papers, warnOnly] — the no-exam/no-label practice
     * scope only WARNS (practice papers legitimately reuse
     * questions); exam/label scopes exclude.
     */
    public static function scopedFinalPapers(array $scope, int $teacherId)
    {
        $base = QuestionPaper::where('status', 'final')
            ->where('my_class_id', (int) $scope['my_class_id'])
            ->where('subject_id', (int) $scope['subject_id']);

        if (!empty($scope['exam_id'])) {
            return [$base->where('exam_id', (int) $scope['exam_id'])->get(), false];
        }

        if (!empty($scope['exam_label'])) {
            $label = static::normaliseLabel($scope['exam_label']);
            $papers = $base->whereNull('exam_id')->get()
                ->filter(fn ($p) => static::normaliseLabel($p->exam_label) === $label)
                ->values();

            return [$papers, false];
        }

        $papers = $base->where('teacher_id', $teacherId)
            ->whereNull('exam_id')
            ->where(function ($q) {
                $q->whereNull('exam_label')->orWhere('exam_label', '');
            })->get();

        return [$papers, true];
    }

    /**
     * Run the full selection for a spec.
     *
     * $scope: my_class_id, section_id, subject_id, exam_id,
     *         exam_label, class_name, subject_name (names only for
     *         shortfall messages).
     *
     * @return array{sections: array, has_shortfall: bool, excluded_total: int,
     *               excluded_from: array, duplicate_warning: ?string}
     */
    public static function select(array $spec, string $seed, int $teacherId, array $scope, bool $allowRepeats): array
    {
        $specHash = static::specHash($spec, $scope);

        [$scopedPapers, $warnOnly] = static::scopedFinalPapers($scope, $teacherId);
        $excludedBankIds = [];
        $excludedTextHashes = [];
        $excludedFrom = [];
        if ($scopedPapers->count()) {
            $scopedIds = $scopedPapers->pluck('id')->all();
            $rows = \App\Models\QuestionPaperQuestion::whereIn('question_paper_id', $scopedIds)->get();
            foreach ($rows as $row) {
                if ($row->bank_question_id) {
                    $excludedBankIds[(int) $row->bank_question_id] = true;
                }
                $excludedTextHashes[$row->text_hash] = true;
            }
            $excludedFrom = $scopedPapers->pluck('title')->all();
        }
        $duplicateWarning = null;
        if ($warnOnly && !$allowRepeats && $scopedPapers->count()) {
            $duplicateWarning = 'Some questions may repeat questions already used in your earlier practice papers for this class and subject.';
        }
        // Kept for the repeat-override audit count (§3.4): when the
        // teacher explicitly allows repeats, how many picked
        // questions repeat a scoped final paper.
        $repeatBankIds = $excludedBankIds;
        $repeatTextHashes = $excludedTextHashes;
        if ($allowRepeats || $warnOnly) {
            $excludedBankIds = [];
            $excludedTextHashes = [];
        }

        $usedBankIds = [];
        $usedTextHashes = [];
        $sectionResults = [];
        $hasShortfall = false;
        $totalExcluded = 0;

        foreach (($spec['sections'] ?? []) as $sectionSpec) {
            $result = static::selectSection(
                $sectionSpec, $seed, $specHash, $teacherId, $scope,
                $excludedBankIds, $excludedTextHashes, $usedBankIds, $usedTextHashes
            );
            foreach ($result['picked'] as $q) {
                $usedBankIds[(int) $q->id] = true;
                $usedTextHashes[static::textHash($q->question)] = true;
            }
            $totalExcluded += $result['excluded_duplicates'];
            if ($result['shortfall']) {
                $hasShortfall = true;
            }
            $sectionResults[$sectionSpec['key']] = $result;
        }

        $repeatCount = 0;
        if ($allowRepeats && !$warnOnly) {
            foreach ($sectionResults as $result) {
                foreach ($result['picked'] as $q) {
                    if (isset($repeatBankIds[(int) $q->id]) || isset($repeatTextHashes[static::textHash($q->question)])) {
                        $repeatCount++;
                    }
                }
            }
        }

        return [
            'sections' => $sectionResults,
            'has_shortfall' => $hasShortfall,
            'excluded_total' => $allowRepeats ? 0 : $totalExcluded,
            'excluded_from' => $allowRepeats ? [] : $excludedFrom,
            'duplicate_warning' => $duplicateWarning,
            'repeat_count' => $repeatCount,
        ];
    }

    protected static function selectSection(array $sectionSpec, string $seed, string $specHash, int $teacherId, array $scope, array $excludedBankIds, array $excludedTextHashes, array $usedBankIds, array $usedTextHashes): array
    {
        $key = $sectionSpec['key'];
        $type = $sectionSpec['type'];
        $count = (int) ($sectionSpec['count'] ?? 0);
        $chapters = array_values(array_filter($sectionSpec['chapters'] ?? [], fn ($c) => $c !== '' && $c !== null));

        $query = QuestionBankQuestion::where('teacher_id', $teacherId)
            ->where('my_class_id', (int) $scope['my_class_id'])
            ->where('subject_id', (int) $scope['subject_id'])
            ->where('question_type', $type);
        if (!empty($scope['section_id'])) {
            $query->where(function ($q) use ($scope) {
                $q->whereNull('section_id')->orWhere('section_id', (int) $scope['section_id']);
            });
        }
        if ($chapters) {
            $query->whereIn('chapter', $chapters);
        }
        $pool = $query->get();

        $excludedCount = 0;
        $candidates = [];
        foreach ($pool as $q) {
            $hash = static::textHash($q->question);
            if (isset($excludedBankIds[(int) $q->id]) || isset($excludedTextHashes[$hash])) {
                $excludedCount++;
                continue;
            }
            if (isset($usedBankIds[(int) $q->id]) || isset($usedTextHashes[$hash])) {
                continue; // within-paper dedupe, across all sections
            }
            $candidates[] = $q;
        }
        $poolSize = count($candidates);

        // Hash order: sha256(seed | spec-hash | bank_id) ASC, id ASC.
        usort($candidates, function ($a, $b) use ($seed, $specHash) {
            $ha = hash('sha256', $seed . '|' . $specHash . '|' . $a->id);
            $hb = hash('sha256', $seed . '|' . $specHash . '|' . $b->id);
            $cmp = strcmp($ha, $hb);

            return $cmp !== 0 ? $cmp : ((int) $a->id <=> (int) $b->id);
        });

        // Difficulty pass: quotas first (deficit flows to "any" and
        // is reported), then the remainder in hash order.
        $difficultyNote = null;
        $mix = $sectionSpec['difficulty_mix'] ?? null;
        if (is_array($mix) && array_sum(array_map('floatval', $mix)) > 0) {
            [$candidates, $difficultyNote] = static::applyDifficultyMix($candidates, $mix, $count);
        } elseif ($poolSize > 0) {
            $unrated = count(array_filter($candidates, fn ($q) => empty($q->difficulty)));
            if ($unrated > 0) {
                $difficultyNote = "{$unrated} of the eligible questions are unrated (no difficulty set).";
            }
        }

        // Marks pass.
        $marksEach = isset($sectionSpec['marks_each']) && $sectionSpec['marks_each'] !== '' && $sectionSpec['marks_each'] !== null
            ? (int) $sectionSpec['marks_each'] : null;
        $marksTotal = isset($sectionSpec['marks_total']) && $sectionSpec['marks_total'] !== '' && $sectionSpec['marks_total'] !== null
            ? (int) $sectionSpec['marks_total'] : null;

        $picked = [];
        $shortfall = null;

        if ($count <= 0) {
            $picked = [];
        } elseif ($marksEach !== null) {
            $eligible = array_values(array_filter($candidates, fn ($q) => (int) $q->marks === $marksEach));
            $picked = array_slice($eligible, 0, $count);
            if (count($picked) < $count) {
                $haveMarks = (int) collect($eligible)->sum('marks');
                $shortfall = static::shortfall($sectionSpec, $scope, $chapters, $count, $count * $marksEach, count($eligible), $haveMarks);
                $picked = $eligible; // partial-draft path uses what exists
            }
        } elseif ($marksTotal !== null) {
            $picked = static::subsetExact($candidates, $count, $marksTotal);
            if ($picked === null) {
                $greedy = static::subsetGreedy($candidates, $count, $marksTotal);
                $shortfall = static::shortfall($sectionSpec, $scope, $chapters, $count, $marksTotal, count($greedy), (int) collect($greedy)->sum('marks'));
                $picked = $greedy;
            }
        } else {
            $picked = array_slice($candidates, 0, $count);
            if (count($picked) < $count) {
                $shortfall = static::shortfall($sectionSpec, $scope, $chapters, $count, (int) collect($picked)->sum('marks'), count($picked), (int) collect($picked)->sum('marks'));
            }
        }

        return [
            'key' => $key,
            'label' => $sectionSpec['label'] ?? $key,
            'type' => $type,
            'picked' => $picked,
            'marks' => (int) collect($picked)->sum('marks'),
            'pool' => $poolSize,
            'excluded_duplicates' => $excludedCount,
            'shortfall' => $shortfall,
            'difficulty_note' => $difficultyNote,
        ];
    }

    protected static function shortfall(array $sectionSpec, array $scope, array $chapters, int $needCount, int $needMarks, int $haveCount, int $haveMarks): array
    {
        $label = $sectionSpec['label'] ?? $sectionSpec['key'];
        $typeName = ['mcq' => 'MCQ', 'short' => 'short', 'long' => 'long'][$sectionSpec['type']] ?? $sectionSpec['type'];
        $where = trim(($scope['class_name'] ?? '') . ' · ' . ($scope['subject_name'] ?? ''));
        $chapterText = $chapters ? ' (Chapters: ' . implode(', ', $chapters) . ')' : '';
        $shortCount = max(0, $needCount - $haveCount);
        $shortMarks = max(0, $needMarks - $haveMarks);
        $message = "Section {$sectionSpec['key']} ({$label}): needs {$needCount} questions / {$needMarks} marks. "
            . "Your bank has {$haveCount} eligible {$typeName} questions for {$where}{$chapterText} = {$haveMarks} marks. "
            . "Short by {$shortCount} questions / {$shortMarks} marks. Add questions to your bank, widen the chapters, or reduce this section — the paper was not created.";

        return [
            'needed_count' => $needCount,
            'needed_marks' => $needMarks,
            'have_count' => $haveCount,
            'have_marks' => $haveMarks,
            'short_count' => $shortCount,
            'short_marks' => $shortMarks,
            'message' => $message,
        ];
    }

    /** @return array{0: array, 1: ?string} reordered candidates + honesty note */
    protected static function applyDifficultyMix(array $candidates, array $mix, int $count): array
    {
        $buckets = ['easy', 'medium', 'hard', 'unrated'];
        $quotas = [];
        $assigned = 0;
        $active = [];
        foreach ($buckets as $b) {
            $pct = (float) ($mix[$b] ?? 0);
            if ($pct > 0) {
                $active[] = $b;
            }
        }
        foreach ($buckets as $b) {
            if (!in_array($b, $active, true)) {
                $quotas[$b] = 0;
                continue;
            }
            if ($b === end($active)) {
                $quotas[$b] = max(0, $count - $assigned);
            } else {
                $quotas[$b] = (int) round($count * ((float) ($mix[$b] ?? 0)) / 100, 0, PHP_ROUND_HALF_UP);
                $assigned += $quotas[$b];
            }
        }

        $taken = [];
        $takenIds = [];
        $notes = [];
        foreach ($buckets as $b) {
            if ($quotas[$b] <= 0) {
                continue;
            }
            $got = 0;
            foreach ($candidates as $q) {
                if ($got >= $quotas[$b]) {
                    break;
                }
                $qd = empty($q->difficulty) ? 'unrated' : $q->difficulty;
                if ($qd === $b && !isset($takenIds[(int) $q->id])) {
                    $taken[] = $q;
                    $takenIds[(int) $q->id] = true;
                    $got++;
                }
            }
            if ($got < $quotas[$b]) {
                $notes[] = "difficulty mix requested {$mix[$b]}% {$b} — only {$got} of {$quotas[$b]} needed {$b} questions were available; the deficit was filled from any difficulty";
            }
        }
        $rest = [];
        foreach ($candidates as $q) {
            if (!isset($takenIds[(int) $q->id])) {
                $rest[] = $q;
            }
        }
        $unrated = count(array_filter($candidates, fn ($q) => empty($q->difficulty)));
        if ($unrated > 0) {
            $notes[] = "{$unrated} of the eligible questions are unrated (no difficulty set) and fill only the unrated/any remainder";
        }

        return [array_merge($taken, $rest), $notes ? implode('; ', $notes) . '.' : null];
    }

    /** Greedy in hash order towards an exact (count, marks_total). */
    protected static function subsetGreedy(array $candidates, int $count, int $total): array
    {
        $picked = [];
        $sum = 0;
        foreach ($candidates as $q) {
            if (count($picked) >= $count) {
                break;
            }
            if ($sum + (int) $q->marks <= $total) {
                $picked[] = $q;
                $sum += (int) $q->marks;
            }
        }

        return $picked;
    }

    /**
     * Exact (count, total) subset: greedy first, then a bounded DP
     * repair over the first ≤60 candidates (totals ≤500 per design;
     * larger totals still run the DP but the candidate cap holds).
     * Returns the picked rows or null when no exact subset exists.
     */
    protected static function subsetExact(array $candidates, int $count, int $total): ?array
    {
        $greedy = static::subsetGreedy($candidates, $count, $total);
        if (count($greedy) === $count && (int) collect($greedy)->sum('marks') === $total) {
            return $greedy;
        }

        $cands = array_slice($candidates, 0, 60);
        // dp["k,s"] = index of the last candidate used; from[] chains back.
        $dp = ['0,0' => -1];
        $from = [];
        foreach ($cands as $i => $q) {
            $m = (int) $q->marks;
            $snapshot = $dp;
            foreach ($snapshot as $stateKey => $lastIdx) {
                [$k, $s] = array_map('intval', explode(',', $stateKey));
                if ($k >= $count || $s + $m > $total) {
                    continue;
                }
                $nk = $k + 1;
                $ns = $s + $m;
                $nKey = $nk . ',' . $ns;
                if (!isset($dp[$nKey])) {
                    $dp[$nKey] = $i;
                    $from[$nKey] = $stateKey;
                }
            }
        }
        $target = $count . ',' . $total;
        if (!isset($dp[$target])) {
            return null;
        }
        $pickedIdx = [];
        $cur = $target;
        while ($cur !== '0,0') {
            $pickedIdx[] = $dp[$cur];
            $cur = $from[$cur];
        }
        $picked = [];
        foreach (array_reverse($pickedIdx) as $i) {
            $picked[] = $cands[$i];
        }

        return $picked;
    }
}
