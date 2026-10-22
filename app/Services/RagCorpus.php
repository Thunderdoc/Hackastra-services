<?php

namespace App\Services;

use App\Helpers\Qs;
use App\Models\AiUsage;
use App\Models\Circular;
use App\Models\RagChunk;
use App\Models\RagDocument;
use App\Services\Embeddings\EmbeddingClient;
use App\Support\Audit;
use App\User;

/**
 * RAG corpus ingestion (Workstream DH; design docs/AI_LAYER.md §1.2).
 *
 * Wave 1 sources: published circulars and admin-pasted policy texts
 * (Knowledge Base in Settings). A rebuild is idempotent: a source whose
 * text, audience roles and embedding model are unchanged keeps its
 * chunks (no re-embed cost); a changed source is re-chunked and
 * re-embedded; a source that vanished (deleted or unpublished
 * circular) loses its registry row and chunks in the same run.
 *
 * Chunk audience roles are copied from the source's own audience model
 * at index time — retrieval never has to guess permissions (§1.4).
 */
class RagCorpus
{
    /** Every user type in the product. */
    public static function allRoles(): array
    {
        return ['super_admin', 'admin', 'teacher', 'accountant', 'librarian', 'student', 'parent'];
    }

    /** Roles that count as school staff for policy visibility. */
    public static function staffRoles(): array
    {
        return ['super_admin', 'admin', 'teacher', 'accountant', 'librarian'];
    }

    /**
     * Which user types may retrieve a circular, derived by inverting
     * Circular::audiencesFor() so the corpus copies the circular's own
     * audience model exactly (§1.1).
     */
    public static function rolesForCircular(Circular $circular): array
    {
        $roles = [];
        foreach (self::allRoles() as $type) {
            if (in_array($circular->audience, Circular::audiencesFor($type), true)) {
                $roles[] = $type;
            }
        }

        return $roles;
    }

    /** Policy texts: staff-only unless the author flagged them public. */
    public static function rolesForPolicy(RagDocument $doc): array
    {
        return $doc->is_public ? self::allRoles() : self::staffRoles();
    }

    /**
     * Paragraph-aware chunking: pack paragraphs up to the target size,
     * hard-split oversized paragraphs at word boundaries, and carry a
     * short tail of the previous chunk into the next (overlap) so a
     * fact split across a boundary stays retrievable from both sides.
     *
     * @return string[]
     */
    public static function chunkText(string $text, ?int $size = null, ?int $overlap = null): array
    {
        $size = $size ?: (int) config('ai.rag.chunk_chars', 800);
        $overlap = $overlap ?: (int) config('ai.rag.chunk_overlap', 120);

        $text = trim(preg_replace("/\r\n?/", "\n", $text));
        if ($text === '') {
            return [];
        }

        $paragraphs = preg_split('/\n{2,}/', $text);
        if (count($paragraphs) === 1) {
            $paragraphs = preg_split('/\n/', $text);
        }

        // Pack paragraphs into size-bounded pieces.
        $pieces = [];
        $current = '';
        foreach ($paragraphs as $paragraph) {
            $paragraph = trim(preg_replace('/\s+/', ' ', $paragraph));
            if ($paragraph === '') {
                continue;
            }
            if (mb_strlen($paragraph) > $size) {
                if ($current !== '') {
                    $pieces[] = $current;
                    $current = '';
                }
                foreach (self::splitLong($paragraph, $size) as $part) {
                    $pieces[] = $part;
                }
                continue;
            }
            if ($current !== '' && mb_strlen($current) + 2 + mb_strlen($paragraph) > $size) {
                $pieces[] = $current;
                $current = '';
            }
            $current = $current === '' ? $paragraph : $current . "\n\n" . $paragraph;
        }
        if ($current !== '') {
            $pieces[] = $current;
        }

        // Apply overlap: prepend the previous piece's tail.
        $chunks = [];
        foreach ($pieces as $i => $piece) {
            if ($i > 0 && $overlap > 0) {
                $tail = mb_substr($pieces[$i - 1], -$overlap);
                $cut = mb_strpos($tail, ' ');
                if ($cut !== false && $cut > 0) {
                    $tail = mb_substr($tail, $cut + 1);
                }
                $piece = $tail . ' ' . $piece;
            }
            $chunks[] = $piece;
        }

        return $chunks;
    }

    protected static function splitLong(string $text, int $size): array
    {
        $parts = [];
        while (mb_strlen($text) > $size) {
            $cut = mb_strrpos(mb_substr($text, 0, $size), ' ');
            if ($cut === false || $cut < (int) ($size * 0.5)) {
                $cut = $size;
            }
            $parts[] = trim(mb_substr($text, 0, $cut));
            $text = trim(mb_substr($text, $cut));
        }
        if ($text !== '') {
            $parts[] = $text;
        }

        return $parts;
    }

    /**
     * Rebuild the whole Wave 1 corpus. Safe to re-run at any time.
     *
     * @return array{documents:int, chunks:int, reembedded:int, skipped:int, removed:int, model:string, mock:bool}
     * @throws RagException when the embedding transport fails
     */
    public static function rebuild(?User $actor = null): array
    {
        $transport = EmbeddingClient::make();
        $model = $transport->model();
        $mock = EmbeddingClient::usingMock();

        // --- Registry sync: published circulars -----------------------
        $circulars = Circular::published()->orderBy('id')->get();
        $liveIds = [];
        foreach ($circulars as $circular) {
            $liveIds[] = $circular->id;
            RagDocument::updateOrCreate(
                ['source_type' => 'circular', 'source_id' => $circular->id],
                ['title' => $circular->title, 'audience' => $circular->audience, 'body' => null]
            );
        }

        $removed = 0;
        $stale = RagDocument::where('source_type', 'circular')
            ->whereNotIn('source_id', $liveIds ?: [0])
            ->get();
        foreach ($stale as $doc) {
            RagChunk::where('rag_document_id', $doc->id)->delete();
            $doc->delete();
            $removed++;
        }

        // --- (Re)embed every registered source ------------------------
        $documents = RagDocument::orderBy('id')->get();
        $reembedded = 0;
        $skipped = 0;
        $embeddedChars = 0;

        try {
            foreach ($documents as $doc) {
                if ($doc->source_type === 'circular') {
                    $circular = $circulars->firstWhere('id', $doc->source_id);
                    if (!$circular) {
                        continue;
                    }
                    $text = $circular->title . "\n\n" . trim(strip_tags((string) $circular->body));
                    $roles = self::rolesForCircular($circular);
                } else {
                    $text = $doc->title . "\n\n" . trim((string) $doc->body);
                    $roles = self::rolesForPolicy($doc);
                }

                $hash = hash('sha256', $text . '|' . json_encode($roles) . '|' . $model);
                $existing = RagChunk::where('rag_document_id', $doc->id)->get();
                if ($existing->isNotEmpty()
                    && $existing->every(function ($chunk) use ($hash, $model) {
                        return $chunk->content_hash === $hash && $chunk->embedding_model === $model;
                    })) {
                    $skipped++;
                    continue;
                }

                $pieces = self::chunkText($text);
                $vectors = $transport->embed($pieces);
                $embeddedChars += mb_strlen($text);

                RagChunk::where('rag_document_id', $doc->id)->delete();
                foreach ($pieces as $i => $piece) {
                    RagChunk::create([
                        'rag_document_id' => $doc->id,
                        'source_type' => $doc->source_type,
                        'source_id' => $doc->source_id,
                        'chunk_index' => $i,
                        'section_title' => $doc->title,
                        'text' => $piece,
                        'audience_roles' => $roles,
                        'embedding' => $vectors[$i] ?? null,
                        'embedding_model' => $model,
                        'content_hash' => $hash,
                    ]);
                }
                $reembedded++;
            }
        } catch (RagException $e) {
            static::meter($actor, 'error', $model);
            throw $e;
        } catch (\Throwable $e) {
            static::meter($actor, 'error', $model);
            throw new RagException('AI is unavailable right now. Please try again later.');
        }

        $chunkTotal = RagChunk::count();
        static::meter($actor, 'ok', $model, (int) ceil($embeddedChars / 4));
        Audit::log('ai.rag_rebuilt', null, 'Knowledge index rebuilt: ' . $documents->count() . ' documents, ' . $chunkTotal . ' chunks (' . $reembedded . ' re-embedded, ' . $skipped . ' unchanged, ' . $removed . ' removed) via ' . ($mock ? 'mock' : (Qs::getSetting('ai_provider') ?: 'gemini')) . '/' . $model);

        return [
            'documents' => $documents->count(),
            'chunks' => $chunkTotal,
            'reembedded' => $reembedded,
            'skipped' => $skipped,
            'removed' => $removed,
            'model' => $model,
            'mock' => $mock,
        ];
    }

    protected static function meter(?User $actor, string $status, ?string $model = null, $promptTokens = null): void
    {
        try {
            AiUsage::create([
                'user_id' => $actor ? $actor->id : null,
                'task' => 'rag_embed',
                'model' => $model,
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => null,
                'status' => $status,
            ]);
        } catch (\Throwable $e) {
            // Metering must never break ingestion itself.
        }
    }
}
