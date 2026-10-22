<?php

namespace App\Services;

use App\Models\Circular;
use App\Models\RagChunk;
use App\Services\Embeddings\EmbeddingClient;
use App\User;
use Illuminate\Support\Collection;

/**
 * Permission-filtered retrieval (Workstream DH; design
 * docs/AI_LAYER.md §1.4). This is the ONLY path from a user question
 * to corpus text, and the order is fixed: tenant (single school in
 * Wave 1) → audience → live re-authorisation → relevance LAST.
 *
 * A chunk whose audience excludes the asker is never scored, never
 * ranked and never sent to the model — it is filtered out before any
 * similarity is computed.
 */
class RagRetriever
{
    /**
     * The candidate set for a user: audience-filtered and re-authorised
     * against the live sources (§1.4 rules 2, 3 and 5).
     */
    public function candidatesFor(User $user): Collection
    {
        $role = $user->user_type;
        $chunks = RagChunk::with('document')->get();

        $circularIds = $chunks->where('source_type', 'circular')->pluck('source_id')->unique()->filter()->all();
        $circulars = $circularIds
            ? Circular::whereIn('id', $circularIds)->get()->keyBy('id')
            : collect();

        return $chunks->filter(function (RagChunk $chunk) use ($role, $circulars) {
            // Audience filter (hard): the asker's role must be listed.
            if (!in_array($role, $chunk->audience_roles ?: [], true)) {
                return false;
            }

            // Answer-time re-authorisation against the live source.
            if ($chunk->source_type === 'circular') {
                $circular = $circulars->get($chunk->source_id);
                return $circular && $circular->isVisibleTo($role);
            }

            // Policy texts: the registry document must still exist (its
            // visibility was copied into audience_roles at index time).
            return $chunk->document !== null;
        })->values();
    }

    /** How many chunks this user could ever be shown (honest UI state). */
    public function visibleChunkCount(User $user): int
    {
        return $this->candidatesFor($user)->count();
    }

    /**
     * Retrieve the top-k permitted chunks for a question, best first.
     * Returns [] when nothing permitted clears the similarity
     * threshold — the caller then refuses honestly (§1.4 rule 4).
     *
     * @return array<int, array{chunk:RagChunk, score:float, label:string, title:string, date:?string}>
     * @throws RagException when the embedding transport fails
     */
    public function retrieve(User $user, string $question, ?int $topK = null): array
    {
        $candidates = $this->candidatesFor($user);
        if ($candidates->isEmpty()) {
            return [];
        }

        $transport = EmbeddingClient::make();
        try {
            $questionVector = $transport->embed([$question])[0] ?? null;
        } catch (RagException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new RagException('AI is unavailable right now. Please try again later.');
        }
        if (!$questionVector) {
            return [];
        }

        $threshold = (float) config('ai.rag.threshold', 0.15);
        $scored = [];
        foreach ($candidates as $chunk) {
            if (!is_array($chunk->embedding) || $chunk->embedding === []) {
                continue;
            }
            $score = self::cosine($questionVector, $chunk->embedding);
            if ($score >= $threshold) {
                $scored[] = ['chunk' => $chunk, 'score' => $score];
            }
        }

        usort($scored, function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        $scored = array_slice($scored, 0, $topK ?: (int) config('ai.rag.top_k', 6));

        // Citation dates come from the live source where it has one
        // (a circular's own date), not from the index registry.
        $hitCircularIds = collect($scored)->pluck('chunk.source_id')->unique()->filter()->all();
        $hitCirculars = $hitCircularIds
            ? Circular::whereIn('id', $hitCircularIds)->get()->keyBy('id')
            : collect();

        return array_map(function ($hit) use ($hitCirculars) {
            $chunk = $hit['chunk'];
            $doc = $chunk->document;
            $isCircular = $chunk->source_type === 'circular';
            $date = null;
            if ($isCircular && ($circular = $hitCirculars->get($chunk->source_id))) {
                $when = $circular->starts_at ?: $circular->created_at;
                $date = $when ? $when->format('d M Y') : null;
            } elseif ($doc) {
                $date = $doc->updated_at ? $doc->updated_at->format('d M Y') : null;
            }

            return [
                'chunk' => $chunk,
                'score' => $hit['score'],
                'label' => $isCircular ? 'Circular' : 'Policy',
                'title' => $doc ? $doc->title : ($chunk->section_title ?: 'Untitled'),
                'date' => $date,
            ];
        }, $scored);
    }

    /** Cosine similarity; 0.0 when either side is a zero vector. */
    public static function cosine(array $a, array $b): float
    {
        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        $n = min(count($a), count($b));
        for ($i = 0; $i < $n; $i++) {
            $dot += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }
        if ($normA <= 0 || $normB <= 0) {
            return 0.0;
        }

        return $dot / (sqrt($normA) * sqrt($normB));
    }
}
