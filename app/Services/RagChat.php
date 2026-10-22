<?php

namespace App\Services;

use App\Helpers\Qs;
use App\Models\AiUsage;
use App\Services\Embeddings\EmbeddingClient;
use App\Services\Embeddings\MockEmbeddingTransport;
use App\Support\Audit;
use App\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Ask Hackstra — the RAG chat (Workstream DH; design docs/AI_LAYER.md
 * §1.4–§1.5, feature §2 item 1, Wave 1).
 *
 * The flow is fixed by the design's grounding contract:
 *   retrieve (permission-filtered, §1.4) → if nothing permitted clears
 *   the threshold, refuse honestly → otherwise compose an answer from
 *   the retrieved chunks ONLY, with sources cited inline and listed.
 *
 * The chat reads only the indexed corpus — never live student, marks
 * or finance tables — and it never writes data. Every ask is metered
 * (ai_usage) and audit-logged, under the same per-user daily cap as
 * the rest of the AI layer.
 */
class RagChat
{
    public static function enabled(): bool
    {
        try {
            return (string) Qs::getSetting('ai_enabled') === '1';
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function model(): string
    {
        return Qs::getSetting('ai_model') ?: 'gemini-2.5-flash';
    }

    /**
     * @return array{answer:string, sources:array, refused:bool}
     * @throws RagException with a user-safe message on any failure
     */
    public static function ask(User $actor, string $question, ?RagRetriever $retriever = null): array
    {
        if (! static::enabled()) {
            throw new RagException('AI is switched off. An admin can enable it in Settings → AI Assistant.');
        }

        $cap = max(1, (int) config('ai.daily_cap', 50));
        $usedToday = AiUsage::where('user_id', $actor->id)
            ->where('status', 'ok')
            ->whereDate('created_at', now()->toDateString())
            ->count();

        if ($usedToday >= $cap) {
            static::meter($actor, 'capped');
            throw new RagException("You have reached today's AI limit ({$cap} uses per day). Please try again tomorrow.");
        }

        $retriever = $retriever ?: new RagRetriever();

        try {
            $hits = $retriever->retrieve($actor, $question);
        } catch (RagException $e) {
            static::meter($actor, 'error');
            throw $e;
        }

        $sources = static::sourceList($hits);

        if ($hits === []) {
            // Threshold refusal (§1.4 rule 4): no permitted document
            // covers the question, so no model call happens at all.
            $answer = "I couldn't find that in the school's documents available to you. "
                . 'If the office has published a circular or policy about it, it will appear here once the knowledge index includes it.';
            static::meter($actor, 'ok');
            Audit::log('ai.rag_asked', null, 'Ask Hackstra refused (no permitted source) for ' . $actor->name . ' (' . $actor->email . ')');

            return ['answer' => $answer, 'sources' => [], 'refused' => true];
        }

        $prompt = static::buildPrompt($question, $hits);
        $model = static::model();

        try {
            $result = EmbeddingClient::usingMock()
                ? static::mockAnswer($question, $hits)
                : static::geminiTransport($prompt, $model);
        } catch (RagException $e) {
            static::meter($actor, 'error', $model);
            throw $e;
        } catch (\Throwable $e) {
            static::meter($actor, 'error', $model);
            throw new RagException('AI is unavailable right now. Please try again later.');
        }

        static::meter($actor, 'ok', $model, $result['prompt_tokens'] ?? null, $result['completion_tokens'] ?? null);
        Audit::log('ai.rag_asked', null, 'Ask Hackstra answered for ' . $actor->name . ' (' . $actor->email . ') from ' . count($sources) . ' source(s) via ' . (EmbeddingClient::usingMock() ? 'mock' : (Qs::getSetting('ai_provider') ?: 'gemini')) . '/' . $model);

        return ['answer' => trim($result['text']), 'sources' => $sources, 'refused' => false];
    }

    /** Unique cited sources, in retrieval order. */
    protected static function sourceList(array $hits): array
    {
        $sources = [];
        $seen = [];
        foreach ($hits as $hit) {
            $key = $hit['label'] . '|' . $hit['title'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $sources[] = [
                'label' => $hit['label'],
                'title' => $hit['title'],
                'date' => $hit['date'],
            ];
        }

        return $sources;
    }

    /* -----------------------------------------------------------------
     | Grounding prompt (§1.5): answer only from the supplied chunks,
     | cite inline in the exact [Circular: title] / [Policy: title]
     | shapes, and say plainly when the documents don't cover it.
     | --------------------------------------------------------------- */
    protected static function buildPrompt(string $question, array $hits): string
    {
        $blocks = [];
        foreach ($hits as $i => $hit) {
            $blocks[] = '[' . ($i + 1) . '] (' . $hit['label'] . ': ' . $hit['title'] . ")\n" . $hit['chunk']->text;
        }

        $school = Qs::getSystemName();

        return "You are Ask Hackstra, the assistant for {$school}. Answer the question using ONLY the school documents provided below.\n"
            . "Rules:\n"
            . "- Use only facts stated in the documents. Never invent dates, fees, names or rules.\n"
            . "- Cite the source of each fact inline, exactly as [Circular: <title>] or [Policy: <title>], matching the document labels.\n"
            . "- If the documents do not contain the answer, reply plainly that it is not in the school's documents.\n"
            . "- Answer in the language of the question. Keep the answer short and direct.\n\n"
            . "Documents:\n" . implode("\n\n", $blocks) . "\n\n"
            . 'Question: ' . $question;
    }

    /* -----------------------------------------------------------------
     | Gemini transport — same REST shape as the rest of the AI layer:
     | POST {base}/v1beta/models/{model}:generateContent, key in the
     | x-goog-api-key header. Failures become the friendly message.
     | --------------------------------------------------------------- */
    protected static function geminiTransport(string $prompt, string $model): array
    {
        $key = Qs::getSetting('ai_api_key');
        $base = rtrim((string) config('ai.gemini.base_url'), '/');
        $url = $base . '/v1beta/models/' . $model . ':generateContent';

        $response = Http::timeout((int) config('ai.gemini.timeout', 20))
            ->withHeaders(['x-goog-api-key' => $key])
            ->acceptJson()
            ->post($url, [
                'contents' => [
                    ['parts' => [['text' => $prompt]]],
                ],
            ]);

        if (!$response->successful()) {
            throw new RagException('AI is unavailable right now. Please try again later.');
        }

        $text = $response->json('candidates.0.content.parts.0.text');
        if (!is_string($text) || trim($text) === '') {
            throw new RagException('AI is unavailable right now. Please try again later.');
        }

        return [
            'text' => $text,
            'prompt_tokens' => $response->json('usageMetadata.promptTokenCount'),
            'completion_tokens' => $response->json('usageMetadata.candidatesTokenCount'),
        ];
    }

    /* -----------------------------------------------------------------
     | Mock answer — extractive and honest: it quotes the best-matching
     | sentence of the top permitted chunk and names its source, so the
     | full flow (retrieve → cite → refuse) is provable without a key.
     | It never invents a fact that is not in the chunk text.
     | --------------------------------------------------------------- */
    protected static function mockAnswer(string $question, array $hits): array
    {
        $top = $hits[0];
        $sentence = static::bestSentence($question, $top['chunk']->text);
        $citation = '[' . $top['label'] . ': ' . $top['title'] . ']';
        $text = 'According to ' . $citation . ', ' . $sentence;
        if (!preg_match('/[.!?]$/', $text)) {
            $text .= '.';
        }

        $prompt = static::buildPrompt($question, $hits);

        return [
            'text' => $text,
            'prompt_tokens' => (int) ceil(strlen($prompt) / 4),
            'completion_tokens' => (int) ceil(strlen($text) / 4),
        ];
    }

    /** The sentence sharing the most meaningful words with the question. */
    protected static function bestSentence(string $question, string $text): string
    {
        $sentences = preg_split('/(?<=[.!?])\s+/', trim($text)) ?: [$text];
        $questionTokens = array_unique(MockEmbeddingTransport::tokens($question));

        $best = $sentences[0];
        $bestScore = -1;
        foreach ($sentences as $sentence) {
            $sentenceTokens = array_unique(MockEmbeddingTransport::tokens($sentence));
            $score = count(array_intersect($questionTokens, $sentenceTokens));
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $sentence;
            }
        }

        return trim($best);
    }

    protected static function meter(User $actor, string $status, ?string $model = null, $promptTokens = null, $completionTokens = null): void
    {
        try {
            AiUsage::create([
                'user_id' => $actor->id,
                'task' => 'rag_ask',
                'model' => $model,
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'status' => $status,
            ]);
        } catch (\Throwable $e) {
            // Metering must never break the ask itself.
        }
    }
}
