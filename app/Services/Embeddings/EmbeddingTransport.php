<?php

namespace App\Services\Embeddings;

/**
 * One doorway for text embeddings (design docs/AI_LAYER.md §1.3's
 * AiClient, embedding half). No feature calls a vendor API directly.
 *
 * Implementations return one float vector per input text, in order.
 * Any failure throws App\Services\RagException with a user-safe message.
 */
interface EmbeddingTransport
{
    /**
     * @param  string[]  $texts
     * @return float[][] one vector per input text
     */
    public function embed(array $texts): array;

    /** The model identifier stored on every chunk this transport makes. */
    public function model(): string;
}
