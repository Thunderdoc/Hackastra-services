<?php

namespace App\Services;

use App\Helpers\Qs;
use App\Models\AiUsage;
use App\Support\Audit;
use App\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * AI Assist v1 (Workstream CX) — the single doorway for every AI help
 * in the product.
 *
 * Product law (locked AI design, non-negotiable):
 *  - AI suggests, a named human confirms. This service only ever
 *    RETURNS text; it never writes a mark, record or circular itself.
 *    Callers drop the text into editable form fields and the human
 *    saves through the normal paths.
 *  - Every generation is metered (ai_usage) and audit-logged
 *    (App\Support\Audit).
 *  - The whole layer is feature-flagged by the `ai_enabled` setting;
 *    with it off, no panel renders and generate() refuses.
 *
 * Transports:
 *  - Gemini (real): REST generateContent against Google's free tier,
 *    key read from the `ai_api_key` setting (write-only in the UI).
 *  - Mock (tests/demos): deterministic templated output, engaged when
 *    AI_MOCK=1 or when AI is on but no key has been pasted yet — so the
 *    assists are fully provable before go-live.
 */
class AiAssist
{
    /** Is the AI layer switched on for this school? */
    public static function enabled(): bool
    {
        try {
            return (string) Qs::getSetting('ai_enabled') === '1';
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function provider(): string
    {
        return Qs::getSetting('ai_provider') ?: 'gemini';
    }

    public static function model(): string
    {
        return Qs::getSetting('ai_model') ?: 'gemini-2.5-flash';
    }

    /** True when the deterministic mock transport will answer. */
    public static function usingMock(): bool
    {
        if (config('ai.mock')) {
            return true;
        }

        return empty(Qs::getSetting('ai_api_key'));
    }

    /**
     * Generate text for a task.
     *
     * @param  string  $task     circular_draft | report_comment | grade_suggestion
     * @param  array   $context  task-specific inputs (see buildPrompt)
     * @throws AiAssistException with a user-safe message on any failure
     */
    public static function generate(string $task, array $context, User $actor): string
    {
        if (! static::enabled()) {
            throw new AiAssistException('AI Assist is switched off. An admin can enable it in Settings.');
        }

        $cap = max(1, (int) config('ai.daily_cap', 50));
        $usedToday = AiUsage::where('user_id', $actor->id)
            ->where('status', 'ok')
            ->whereDate('created_at', now()->toDateString())
            ->count();

        if ($usedToday >= $cap) {
            static::meter($actor, $task, 'capped');
            throw new AiAssistException("You have reached today's AI limit ({$cap} generations per day). Please try again tomorrow.");
        }

        $prompt = static::buildPrompt($task, $context);
        $model = static::model();

        try {
            $result = static::usingMock()
                ? static::mockTransport($task, $context, $prompt)
                : static::geminiTransport($prompt, $model);
        } catch (AiAssistException $e) {
            static::meter($actor, $task, 'error', $model);
            throw $e;
        } catch (\Throwable $e) {
            static::meter($actor, $task, 'error', $model);
            throw new AiAssistException('AI is unavailable right now. Please try again later.');
        }

        static::meter($actor, $task, 'ok', $model, $result['prompt_tokens'] ?? null, $result['completion_tokens'] ?? null);
        Audit::log('ai.generated', null, 'AI task "' . $task . '" generated for ' . $actor->name . ' (' . $actor->email . ') via ' . (static::usingMock() ? 'mock' : static::provider()) . '/' . $model);

        return trim($result['text']);
    }

    /**
     * ASSIST 3 (service only in v1) — grading suggestion for one LMS
     * submission. The parent wires this into the LMS grading screen at
     * the LMS milestone: call it when a teacher opens a text submission,
     * pre-fill the marks + feedback inputs with the return values, and
     * let the teacher adjust and save through the LMS's own save path.
     * Nothing is persisted here — suggestion only.
     *
     * @return array{suggested_marks: float, feedback: string}
     */
    public static function suggestGrade(string $question, string $rubric, string $answerText, $maxMarks, ?User $actor = null): array
    {
        $actor = $actor ?: auth()->user();
        if (!$actor) {
            throw new AiAssistException('AI grading suggestions need a signed-in user.');
        }

        $text = static::generate('grade_suggestion', [
            'question' => $question,
            'rubric' => $rubric,
            'answer' => $answerText,
            'max_marks' => $maxMarks,
        ], $actor);

        $data = static::decodeJson($text);
        if ($data === null || !isset($data['suggested_marks'])) {
            throw new AiAssistException('AI returned an unreadable suggestion. Please grade this one manually.');
        }

        $marks = max(0, min((float) $maxMarks, (float) $data['suggested_marks']));

        return [
            'suggested_marks' => round($marks, 1),
            'feedback' => trim((string) ($data['feedback'] ?? '')),
        ];
    }

    /* -----------------------------------------------------------------
     | Prompt construction — one builder per task. Context numbers are
     | always computed by the caller from real records; the model is
     | instructed to use ONLY what it is given.
     | --------------------------------------------------------------- */
    protected static function buildPrompt(string $task, array $context): string
    {
        switch ($task) {
            case 'circular_draft':
                $school = $context['school_name'] ?? Qs::getSystemName();
                return "You are drafting a school circular for {$school}.\n"
                    . "Write it in {$context['language']} with a {$context['tone']} tone.\n"
                    . "The circular must be based ONLY on these points from the school office: {$context['brief']}\n"
                    . "Reply with ONLY a JSON object: {\"title\": \"<short title>\", \"body\": \"<the circular message, 2 short paragraphs, signed as The School Office>\"}";

            case 'report_comment':
                $lines = [];
                foreach ($context['subjects'] as $s) {
                    $lines[] = "- {$s['name']}: {$s['score']}%";
                }
                return "You are a class teacher writing a report-card remark for {$context['student_name']} ({$context['class_name']}, {$context['exam_name']}).\n"
                    . "Real subject scores (percent):\n" . implode("\n", $lines) . "\n"
                    . "Overall average: {$context['average']}%. Strongest subject: {$context['strongest']}. Weakest subject: {$context['weakest']}.\n"
                    . "Write a warm, honest remark of 2-3 short sentences mentioning the strongest and weakest subjects by name and one concrete next step. Use ONLY the data given. Reply with the remark text only, no JSON.";

            case 'grade_suggestion':
                return "You are helping a teacher grade a student's assignment answer.\n"
                    . "Question: {$context['question']}\n"
                    . "Marking rubric: {$context['rubric']}\n"
                    . "Maximum marks: {$context['max_marks']}\n"
                    . "Student's answer: {$context['answer']}\n"
                    . "Reply with ONLY a JSON object: {\"suggested_marks\": <number between 0 and {$context['max_marks']}>, \"feedback\": \"<2-3 sentences of constructive feedback addressed to the student>\"}";

            default:
                return (string) ($context['prompt'] ?? '');
        }
    }

    /* -----------------------------------------------------------------
     | Gemini transport — REST per Google's current docs: POST
     | {base}/v1beta/models/{model}:generateContent with the API key in
     | the x-goog-api-key header. Any failure becomes the friendly
     | "unavailable" message; raw provider errors are never surfaced.
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
            throw new AiAssistException('AI is unavailable right now. Please try again later.');
        }

        $text = $response->json('candidates.0.content.parts.0.text');
        if (!is_string($text) || trim($text) === '') {
            throw new AiAssistException('AI is unavailable right now. Please try again later.');
        }

        return [
            'text' => $text,
            'prompt_tokens' => $response->json('usageMetadata.promptTokenCount'),
            'completion_tokens' => $response->json('usageMetadata.candidatesTokenCount'),
        ];
    }

    /* -----------------------------------------------------------------
     | Mock transport — deterministic, templated output per task so the
     | full assist flow (panel → generate → fill → human saves) can be
     | proven without a real key. Numbers come from the caller's real
     | context; the mock never invents marks or facts of its own.
     | --------------------------------------------------------------- */
    protected static function mockTransport(string $task, array $context, string $prompt): array
    {
        switch ($task) {
            case 'circular_draft':
                $text = json_encode(static::mockCircular($context));
                break;

            case 'report_comment':
                $text = static::mockReportComment($context);
                break;

            case 'grade_suggestion':
                $text = json_encode(static::mockGrade($context));
                break;

            default:
                $text = 'AI draft (preview mode): ' . Str::limit($prompt, 200);
        }

        return [
            'text' => $text,
            'prompt_tokens' => (int) ceil(strlen($prompt) / 4),
            'completion_tokens' => (int) ceil(strlen($text) / 4),
        ];
    }

    protected static function mockCircular(array $context): array
    {
        $brief = trim($context['brief']);
        $school = $context['school_name'] ?? Qs::getSystemName();
        $title = Str::title(Str::limit(preg_replace('/\s+/', ' ', $brief), 60, ''));
        $friendly = ($context['tone'] ?? 'Formal') === 'Friendly';

        $bodies = [
            'English' => $friendly
                ? "Dear Parents and Students,\n\nWe are happy to share an update from {$school}: {$brief}. Please make a note of it, and feel free to reach out to the school office if you have any questions.\n\nWarm regards,\nThe School Office"
                : "Dear Parents and Students,\n\nThis is to inform you that {$brief}. Parents and students are requested to take note of the above and cooperate accordingly.\n\nBy order,\nThe School Office, {$school}",
            'Hindi' => "प्रिय अभिभावकगण एवं विद्यार्थी,\n\n{$school} की ओर से सूचित किया जाता है: {$brief}। कृपया इसका ध्यान रखें। किसी भी जानकारी के लिए विद्यालय कार्यालय से संपर्क करें।\n\nधन्यवाद,\nविद्यालय कार्यालय",
            'Tamil' => "அன்புள்ள பெற்றோர்களே மற்றும் மாணவர்களே,\n\n{$school} சார்பாகத் தெரிவித்துக் கொள்வது: {$brief}. தயவுசெய்து இதைக் குறித்துக் கொள்ளவும். மேலும் விவரங்களுக்கு பள்ளி அலுவலகத்தைத் தொடர்பு கொள்ளவும்.\n\nநன்றி,\nபள்ளி அலுவலகம்",
        ];

        return [
            'title' => $title,
            'body' => $bodies[$context['language'] ?? 'English'] ?? $bodies['English'],
        ];
    }

    protected static function mockReportComment(array $context): string
    {
        $name = Str::before($context['student_name'], ' ');
        $avg = $context['average'];

        $opener = $avg >= 75
            ? "{$name} has had an excellent term"
            : ($avg >= 55 ? "{$name} has had a good term" : ($avg >= 40 ? "{$name} has had a fair term" : "{$name} has had a challenging term"));

        $step = $avg >= 55
            ? "With a little more consistency in {$context['weakest']}, the results can improve further next term."
            : "Regular daily revision in {$context['weakest']} is the most important next step for {$name}.";

        return "{$opener}, with an overall average of {$avg}%. "
            . "{$context['strongest']} is the strongest subject, while {$context['weakest']} needs more attention. "
            . $step;
    }

    protected static function mockGrade(array $context): array
    {
        $max = (float) $context['max_marks'];
        $answer = mb_strtolower((string) $context['answer']);
        $rubricWords = collect(preg_split('/[^a-z0-9]+/i', (string) $context['rubric']))
            ->filter(function ($w) { return strlen($w) > 3; })
            ->map(function ($w) { return mb_strtolower($w); })
            ->unique();

        $hits = $rubricWords->filter(function ($w) use ($answer) { return Str::contains($answer, $w); })->count();
        $ratio = $rubricWords->count() ? $hits / $rubricWords->count() : 0.5;
        // An empty or near-empty answer can never score from keywords alone.
        if (mb_strlen(trim((string) $context['answer'])) < 10) {
            $ratio = 0;
        }

        $marks = round($max * $ratio, 1);
        $feedback = $ratio >= 0.7
            ? 'Good work — your answer covers most of the key points in the rubric. Check the few remaining points to make it complete.'
            : ($ratio >= 0.35
                ? 'A fair attempt — some key points are covered, but important parts of the rubric are missing. Revise those topics and try to write fuller answers.'
                : 'This answer misses most of the key points asked for. Please re-read the question and the lesson, then rewrite it covering each rubric point.');

        return ['suggested_marks' => $marks, 'feedback' => $feedback];
    }

    /* -----------------------------------------------------------------
     | Helpers
     | --------------------------------------------------------------- */
    protected static function meter(User $actor, string $task, string $status, ?string $model = null, $promptTokens = null, $completionTokens = null): void
    {
        try {
            AiUsage::create([
                'user_id' => $actor->id,
                'task' => $task,
                'model' => $model,
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'status' => $status,
            ]);
        } catch (\Throwable $e) {
            // Metering must never break the assist itself.
        }
    }

    /** Extract a JSON object from model output (handles code fences). */
    public static function decodeJson(string $text): ?array
    {
        $text = trim($text);
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $text);
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }
        $data = json_decode(substr($text, $start, $end - $start + 1), true);

        return is_array($data) ? $data : null;
    }
}
