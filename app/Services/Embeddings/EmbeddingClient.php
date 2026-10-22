<?php

namespace App\Services\Embeddings;

use App\Helpers\Qs;

/**
 * Picks the live embedding transport (CX's usingMock convention):
 * the deterministic mock answers when AI_MOCK=1 or when AI is on but
 * no API key has been pasted yet; the real Gemini transport otherwise.
 */
class EmbeddingClient
{
    public static function usingMock(): bool
    {
        if (config('ai.mock')) {
            return true;
        }

        return empty(Qs::getSetting('ai_api_key'));
    }

    public static function make(): EmbeddingTransport
    {
        return static::usingMock()
            ? new MockEmbeddingTransport()
            : new GeminiEmbeddingTransport();
    }

    public static function modelName(): string
    {
        return static::make()->model();
    }
}
