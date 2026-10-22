<?php

namespace App\Services\Embeddings;

/**
 * Deterministic stand-in embeddings for tests and pre-go-live demos
 * (engaged by AI_MOCK=1 or when no API key is saved — CX's pattern).
 *
 * Each text becomes a sparse count vector over hashed tokens in a
 * fixed-dimension space, L2-normalised. Texts that share meaningful
 * words land near each other under cosine similarity; texts that share
 * none score ~0. Same input → same vector, on any machine, forever:
 * crc32 is the only hash involved, and common stopwords are dropped so
 * grammar words cannot fake relevance.
 */
class MockEmbeddingTransport implements EmbeddingTransport
{
    protected $dims;

    protected static $stopwords = [
        'a', 'an', 'the', 'is', 'are', 'was', 'were', 'be', 'been', 'am',
        'what', 'when', 'where', 'who', 'whom', 'which', 'why', 'how',
        'do', 'does', 'did', 'can', 'could', 'will', 'would', 'shall',
        'should', 'may', 'might', 'must', 'i', 'you', 'he', 'she', 'it',
        'we', 'they', 'me', 'him', 'her', 'us', 'them', 'my', 'your',
        'his', 'its', 'our', 'their', 'this', 'that', 'these', 'those',
        'of', 'in', 'on', 'at', 'to', 'for', 'with', 'by', 'from', 'as',
        'and', 'or', 'but', 'if', 'then', 'so', 'than', 'too', 'very',
        'not', 'no', 'yes', 'please', 'tell', 'about', 'there', 'any',
    ];

    public function __construct(?int $dims = null)
    {
        $this->dims = $dims ?: (int) config('ai.rag.mock_dims', 256);
    }

    public function model(): string
    {
        return 'mock-hash-' . $this->dims;
    }

    public function embed(array $texts): array
    {
        return array_map(function ($text) {
            return $this->vectorFor($text);
        }, $texts);
    }

    /** Tokenise the same way for documents and questions. */
    public static function tokens(string $text): array
    {
        $words = preg_split('/[^a-z0-9]+/i', mb_strtolower($text)) ?: [];
        $tokens = [];
        foreach ($words as $word) {
            if (strlen($word) < 2 || in_array($word, self::$stopwords, true)) {
                continue;
            }
            $tokens[] = $word;
        }

        return $tokens;
    }

    protected function vectorFor(string $text): array
    {
        $vector = array_fill(0, $this->dims, 0.0);
        foreach (self::tokens($text) as $token) {
            $vector[crc32($token) % $this->dims] += 1.0;
        }

        $norm = sqrt(array_sum(array_map(function ($v) {
            return $v * $v;
        }, $vector)));

        if ($norm <= 0) {
            return $vector;
        }

        return array_map(function ($v) use ($norm) {
            return $v / $norm;
        }, $vector);
    }
}
