<?php

namespace App\Services\Embeddings;

use App\Helpers\Qs;
use App\Services\RagException;
use Illuminate\Support\Facades\Http;

/**
 * Gemini embeddings over REST: POST
 * {base}/v1beta/models/{model}:batchEmbedContents with the API key in
 * the x-goog-api-key header (the same placement the generation
 * transport uses). The model comes from the ai_embedding_model setting
 * (default text-embedding-004 — multilingual, a hard requirement of
 * the design, §1.8). Requests are batched (100 per call, the API's
 * batch limit).
 */
class GeminiEmbeddingTransport implements EmbeddingTransport
{
    protected $model;

    public function __construct(?string $model = null)
    {
        $this->model = $model ?: (Qs::getSetting('ai_embedding_model') ?: 'text-embedding-004');
    }

    public function model(): string
    {
        return $this->model;
    }

    public function embed(array $texts): array
    {
        $key = Qs::getSetting('ai_api_key');
        if (empty($key)) {
            throw new RagException('AI is unavailable right now. Please try again later.');
        }

        $base = rtrim((string) config('ai.gemini.base_url'), '/');
        $url = $base . '/v1beta/models/' . $this->model . ':batchEmbedContents';

        $vectors = [];
        foreach (array_chunk($texts, 100) as $batch) {
            $requests = [];
            foreach ($batch as $text) {
                $requests[] = [
                    'model' => 'models/' . $this->model,
                    'content' => ['parts' => [['text' => $text]]],
                ];
            }

            $response = Http::timeout((int) config('ai.gemini.timeout', 20))
                ->withHeaders(['x-goog-api-key' => $key])
                ->acceptJson()
                ->post($url, ['requests' => $requests]);

            if (!$response->successful()) {
                throw new RagException('AI is unavailable right now. Please try again later.');
            }

            $embeddings = $response->json('embeddings');
            if (!is_array($embeddings) || count($embeddings) !== count($batch)) {
                throw new RagException('AI is unavailable right now. Please try again later.');
            }

            foreach ($embeddings as $embedding) {
                $values = $embedding['values'] ?? null;
                if (!is_array($values)) {
                    throw new RagException('AI is unavailable right now. Please try again later.');
                }
                $vectors[] = array_map('floatval', $values);
            }
        }

        return $vectors;
    }
}
