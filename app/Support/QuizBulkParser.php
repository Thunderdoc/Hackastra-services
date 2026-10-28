<?php

namespace App\Support;

/**
 * Quiz v2 — bulk question parser (server-side only).
 *
 * Format: blocks separated by blank lines. Each block:
 *   question line(s) — every line until the first option line
 *   option lines — "A) text" (letters A–J, delimiter ), . or :)
 *   answer line — "Answer: B" (letter) or "Answer: 2" (1-based number)
 *
 * Returns ['questions' => [...], 'errors' => [...]] with one error
 * string per failed block ("Block 3: ..."). A failed parse saves
 * NOTHING: the caller returns all errors to the teacher with the
 * original text preserved, so no block is ever silently dropped.
 * An answer letter refers to the option carrying that letter; an
 * answer number refers to the 1-based position.
 */
class QuizBulkParser
{
    public static function parse(string $text, int $marksPerQuestion): array
    {
        $questions = [];
        $errors = [];

        $blocks = preg_split('/\n\s*\n/', trim($text));
        foreach ($blocks as $i => $block) {
            $n = $i + 1;
            $lines = array_values(array_filter(array_map('trim', explode("\n", $block)), function ($l) {
                return $l !== '';
            }));
            if (empty($lines)) {
                continue;
            }

            $questionLines = [];
            $options = []; // each: ['label' => 'A', 'text' => '...']
            $labels = [];
            $answerRaw = null;
            $answerSeen = false;
            $blockError = null;

            foreach ($lines as $line) {
                if (preg_match('/^(answer|ans)\s*:\s*(.+)$/i', $line, $m)) {
                    if ($answerSeen) {
                        $blockError = "Block {$n}: more than one Answer line.";
                        break;
                    }
                    $answerSeen = true;
                    $answerRaw = trim($m[2]);
                    continue;
                }
                if ($answerSeen) {
                    $blockError = "Block {$n}: unexpected line after the Answer line: \"{$line}\".";
                    break;
                }
                if (preg_match('/^([A-Ja-j])[).:]\s*(.+)$/', $line, $m)) {
                    $label = strtoupper($m[1]);
                    $optText = trim($m[2]);
                    if ($optText === '') {
                        $blockError = "Block {$n}: option {$label} has no text.";
                        break;
                    }
                    if (isset($labels[$label])) {
                        $blockError = "Block {$n}: option {$label} is listed twice.";
                        break;
                    }
                    $labels[$label] = true;
                    $options[] = ['label' => $label, 'text' => $optText];
                    continue;
                }
                if (!empty($options)) {
                    // A plain line between options is not an option.
                    $blockError = "Block {$n}: line is not an option (use \"A) text\"): \"{$line}\".";
                    break;
                }
                $questionLines[] = $line;
            }

            if ($blockError) {
                $errors[] = $blockError;
                continue;
            }
            $questionText = trim(implode(' ', $questionLines));
            if ($questionText === '') {
                $errors[] = "Block {$n}: no question text before the options.";
                continue;
            }
            if (count($options) < 2) {
                $errors[] = "Block {$n}: needs at least 2 options.";
                continue;
            }
            if (!$answerSeen) {
                $errors[] = "Block {$n}: no Answer line (write \"Answer: B\" or \"Answer: 2\").";
                continue;
            }
            $correctIndex = null;
            if (preg_match('/^[A-Ja-j]$/', $answerRaw)) {
                $want = strtoupper($answerRaw);
                foreach ($options as $idx => $opt) {
                    if ($opt['label'] === $want) {
                        $correctIndex = $idx;
                        break;
                    }
                }
                if ($correctIndex === null) {
                    $errors[] = "Block {$n}: answer \"{$answerRaw}\" has no matching option.";
                    continue;
                }
            } elseif (preg_match('/^\d+$/', $answerRaw)) {
                $pos = (int) $answerRaw;
                if ($pos < 1 || $pos > count($options)) {
                    $errors[] = "Block {$n}: answer number {$pos} is out of range (1–" . count($options) . ").";
                    continue;
                }
                $correctIndex = $pos - 1;
            } else {
                $errors[] = "Block {$n}: answer must be one letter (A–" . chr(64 + count($options)) . ") or one number (1–" . count($options) . ").";
                continue;
            }

            $questions[] = [
                'question' => $questionText,
                'options' => array_column($options, 'text'),
                'correct_index' => $correctIndex,
                'marks' => $marksPerQuestion,
            ];
        }

        return ['questions' => $questions, 'errors' => $errors];
    }
}
