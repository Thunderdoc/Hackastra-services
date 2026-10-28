<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\AiUsage;
use App\Models\Circular;
use App\Models\RagChunk;
use App\Models\RagDocument;
use App\Models\Setting;
use App\Services\AiAssist;
use App\Services\RagChat;
use App\Services\RagRetriever;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Agent G round 3 — AI settings consumer chain + Knowledge Base
 * retrieval chain. All runs on a snapshot DB; every change rolls back
 * via DatabaseTransactions. No write ever touches the shared DB.
 *
 * Reader map (grep over app + views + routes, round 3):
 * - ai_enabled: RagChat::enabled() (Ask page + ask endpoint),
 *   AiAssist::enabled() (circular create form, marks comments blade,
 *   both AiAssist endpoints via generate()).
 * - ai_model: AiAssist::model() + RagChat::model() — audit/metering
 *   strings + the Gemini request URL only; the mock transport ignores
 *   it (proven below: identical mock output under two model values).
 * - ai_provider: validation restricts it to `gemini` (SettingUpdate +
 *   AiSettingsController `in:gemini`); audit strings only. The mock
 *   transport never reads it.
 * - ai_api_key: empty => mock transports (AiAssist::usingMock,
 *   EmbeddingClient::usingMock); set => real Gemini leg (unproven live,
 *   needs a real key — carried from r2, not re-litigated here).
 * - ai_embedding_model: GeminiEmbeddingTransport default only; the
 *   mock transport stamps chunks `mock-hash-*` regardless.
 */
class OwnerSettingsWorkflowRound3Test extends TestCase
{
    use DatabaseTransactions;

    private function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    private function parent()
    {
        return User::where('email', 'parent@hackstra.app')->firstOrFail();
    }

    private function setting(string $type): ?string
    {
        $row = Setting::where('type', $type)->first();

        return $row ? $row->description : null;
    }

    private function setSetting(string $type, string $value): void
    {
        Setting::updateOrCreate(['type' => $type], ['description' => $value]);
    }

    /** Required fields for a full school-form save (r1 shape). */
    private function basePayload(): array
    {
        return [
            'system_name' => $this->setting('system_name') ?? 'Hackstra Academy',
            'current_session' => $this->setting('current_session') ?? '2026-2027',
            'address' => $this->setting('address') ?? 'Madurai, Tamil Nadu, India',
        ];
    }

    /** Toggle AI via the REAL settings save (main form, r1-proven). */
    private function putSettings(array $overrides = [])
    {
        return $this->actingAs($this->admin())
            ->from(route('settings'))
            ->put(route('settings.update'), array_merge($this->basePayload(), $overrides));
    }

    private function enableAi(array $extra = []): void
    {
        $this->putSettings(array_merge([
            'ai_enabled' => 1, 'ai_provider' => 'gemini',
            'ai_model' => 'gemini-2.5-flash', 'ai_api_key' => '',
        ], $extra))->assertRedirect(route('settings'));
        $this->assertSame('1', (string) $this->setting('ai_enabled'));
        $this->assertSame('', (string) $this->setting('ai_api_key'));
    }

    private function disableAi(): void
    {
        $this->putSettings(['ai_enabled' => 0, 'ai_provider' => 'gemini'])
            ->assertRedirect(route('settings'));
        $this->assertSame('0', (string) $this->setting('ai_enabled'));
    }

    private function storePolicy(string $title, string $body, bool $public = true)
    {
        $this->actingAs($this->admin())
            ->from(route('settings'))
            ->post(route('settings.knowledge.store'), [
                'title' => $title, 'body' => $body, 'is_public' => $public,
            ])->assertRedirect(route('settings'))
            ->assertSessionHas('flash_success');

        return RagDocument::where('title', $title)->firstOrFail();
    }

    // ---------- (2) ai_enabled toggle: Ask Hackstra ----------

    public function test_ai_is_off_in_seed()
    {
        $this->assertSame('0', (string) $this->setting('ai_enabled'));
        $this->assertFalse(RagChat::enabled());
        $this->assertFalse(AiAssist::enabled());
    }

    public function test_ai_off_ask_page_shows_disabled_state_no_form()
    {
        $this->disableAi();
        $html = $this->actingAs($this->admin())->get(route('ask.index'))
            ->assertOk()->getContent();
        $this->assertStringContainsString('Ask Hackstra is currently switched off', $html);
        $this->assertStringContainsString('Currently unavailable', $html);
        $this->assertStringNotContainsString('id="ask-form"', $html);
    }

    public function test_ai_off_ask_post_refused_with_exact_flash()
    {
        $this->disableAi();
        $this->actingAs($this->admin())
            ->from(route('ask.index'))
            ->post(route('ask.ask'), ['question' => 'When is the Annual Sports Day?'])
            ->assertRedirect(route('ask.index'))
            ->assertSessionHas('flash_danger', 'AI is switched off. An admin can enable it in Settings → AI Assistant.');
    }

    public function test_ai_on_ask_page_shows_chat_form_and_empty_corpus_warning()
    {
        $this->enableAi();
        $html = $this->actingAs($this->admin())->get(route('ask.index'))
            ->assertOk()->getContent();
        $this->assertStringContainsString('Switched on', $html);
        $this->assertStringContainsString('id="ask-form"', $html);
        // Fresh snapshot has no indexed chunks until a rebuild runs.
        $this->assertStringContainsString('Nothing has been indexed for your role yet', $html);
    }

    public function test_ai_on_with_no_corpus_ask_refuses_honestly()
    {
        $this->enableAi();
        $this->assertSame(0, RagChunk::count());
        $response = $this->actingAs($this->admin())
            ->from(route('ask.index'))
            ->post(route('ask.ask'), ['question' => 'When is the Annual Sports Day?'])
            ->assertRedirect(route('ask.index'));
        $response->assertSessionHas('rag_refused', true);
        $answer = session('rag_answer');
        $this->assertStringContainsString("couldn't find that in the school's documents", $answer);
        $this->assertSame([], session('rag_sources', []));
    }

    // ---------- (2) ai_enabled toggle: AiAssist endpoints + form ----------

    public function test_ai_off_circular_draft_endpoint_refused_422()
    {
        $this->disableAi();
        $this->actingAs($this->admin())
            ->postJson(route('ai.circular_draft'), [
                'brief' => 'PTM Saturday 10am', 'tone' => 'Formal', 'language' => 'English',
            ])->assertStatus(422)
            ->assertJson(['message' => 'AI Assist is switched off. An admin can enable it in Settings.']);
    }

    public function test_ai_on_circular_draft_works_via_mock()
    {
        $this->enableAi();
        $this->actingAs($this->admin())
            ->postJson(route('ai.circular_draft'), [
                'brief' => 'PTM Saturday 10am', 'tone' => 'Formal', 'language' => 'English',
            ])->assertOk()
            ->assertJsonStructure(['title', 'body']);
        $this->assertDatabaseHas('ai_usage', [
            'user_id' => $this->admin()->id, 'task' => 'circular_draft', 'status' => 'ok',
        ]);
    }

    public function test_ai_toggle_flips_circular_create_page_panel()
    {
        $this->disableAi();
        $off = $this->actingAs($this->admin())->get(route('circulars.create'))
            ->assertOk()->getContent();
        $this->assertStringNotContainsString('Draft with AI', $off);
        $this->assertStringNotContainsString('ai_draft_btn', $off);

        $this->enableAi();
        $on = $this->actingAs($this->admin())->get(route('circulars.create'))
            ->assertOk()->getContent();
        $this->assertStringContainsString('Draft with AI', $on);
        $this->assertStringContainsString('ai_draft_btn', $on);
    }

    // ---------- (2) ai_model / ai_provider: what they do NOT change ----------

    public function test_ai_model_change_leaves_mock_output_identical()
    {
        $this->enableAi();
        $payload = [
            'brief' => 'PTM Saturday 10am', 'tone' => 'Formal', 'language' => 'English',
        ];
        $first = $this->actingAs($this->admin())
            ->postJson(route('ai.circular_draft'), $payload)->assertOk()->json();

        $this->putSettings(['ai_enabled' => 1, 'ai_model' => 'g3-other-model'])
            ->assertRedirect(route('settings'));
        $this->assertSame('g3-other-model', AiAssist::model());

        $second = $this->actingAs($this->admin())
            ->postJson(route('ai.circular_draft'), $payload)->assertOk()->json();
        $this->assertSame($first, $second, 'Mock output must not depend on ai_model');
        // The model value surfaces only in metering/audit, not output.
        $this->assertDatabaseHas('ai_usage', [
            'user_id' => $this->admin()->id, 'task' => 'circular_draft',
            'model' => 'g3-other-model', 'status' => 'ok',
        ]);
    }

    public function test_ai_provider_is_gemini_only_via_validation()
    {
        $this->actingAs($this->admin())
            ->from(route('settings'))
            ->put(route('settings.update'), array_merge($this->basePayload(), [
                'ai_enabled' => 1, 'ai_provider' => 'openai',
            ]))->assertSessionHasErrors('ai_provider');
        $this->assertSame('gemini', (string) $this->setting('ai_provider'));
    }

    // ---------- (3) Knowledge Base retrieval chain ----------

    public function test_public_policy_doc_surfaces_in_ask_answer_with_citation()
    {
        $this->enableAi();
        $doc = $this->storePolicy(
            'G3 Uniform Policy',
            'The school uniform for winter is a navy blue blazer with the school crest. Students must wear the blazer every winter morning.',
            true
        );
        $this->assertTrue(RagChunk::where('rag_document_id', $doc->id)->exists());
        $this->assertGreaterThan(0, (new RagRetriever())->visibleChunkCount($this->admin()));

        $response = $this->actingAs($this->admin())
            ->from(route('ask.index'))
            ->post(route('ask.ask'), ['question' => 'What is the winter school uniform?'])
            ->assertRedirect(route('ask.index'));
        $response->assertSessionHas('rag_refused', false);
        $this->assertStringContainsString('navy blue blazer', session('rag_answer'));
        $sources = session('rag_sources', []);
        $this->assertNotEmpty($sources);
        $this->assertSame('Policy', $sources[0]['label']);
        $this->assertSame('G3 Uniform Policy', $sources[0]['title']);

        $html = $this->actingAs($this->admin())->get(route('ask.index'))
            ->assertOk()->getContent();
        $this->assertStringContainsString('[Policy: G3 Uniform Policy]', $html);
    }

    public function test_staff_only_policy_hidden_from_parent_but_visible_to_admin()
    {
        $this->enableAi();
        $this->storePolicy(
            'G3 Staff Duty Roster',
            'The staff duty roster for morning assembly assigns teachers to gate duty on rotation.',
            false
        );

        $this->actingAs($this->parent())
            ->from(route('ask.index'))
            ->post(route('ask.ask'), ['question' => 'What is the staff duty roster for morning assembly?'])
            ->assertRedirect(route('ask.index'))
            ->assertSessionHas('rag_refused', true);
        $this->assertStringContainsString("couldn't find that", session('rag_answer'));

        $this->actingAs($this->admin())
            ->from(route('ask.index'))
            ->post(route('ask.ask'), ['question' => 'What is the staff duty roster for morning assembly?'])
            ->assertRedirect(route('ask.index'))
            ->assertSessionHas('rag_refused', false);
        $this->assertStringContainsString('gate duty', session('rag_answer'));
    }

    public function test_deleted_policy_vanishes_from_answers()
    {
        $this->enableAi();
        $doc = $this->storePolicy(
            'G3 Vanishing Policy',
            'The vanishing policy says the library opens at sunrise for early readers.',
            true
        );
        $this->actingAs($this->admin())
            ->from(route('ask.index'))
            ->post(route('ask.ask'), ['question' => 'When does the library open for early readers?'])
            ->assertSessionHas('rag_refused', false);

        $this->actingAs($this->admin())
            ->from(route('settings'))
            ->delete(route('settings.knowledge.destroy', $doc))
            ->assertRedirect(route('settings'))
            ->assertSessionHas('flash_success', 'Knowledge document deleted and removed from the index.');
        $this->assertDatabaseMissing('rag_documents', ['id' => $doc->id]);
        $this->assertSame(0, RagChunk::where('rag_document_id', $doc->id)->count());

        $this->actingAs($this->admin())
            ->from(route('ask.index'))
            ->post(route('ask.ask'), ['question' => 'When does the library open for early readers?'])
            ->assertRedirect(route('ask.index'))
            ->assertSessionHas('rag_refused', true);
    }

    public function test_published_circular_surfaces_in_ask_answer()
    {
        $this->enableAi();
        $circular = Circular::create([
            'title' => 'G3 Sports Day Circular',
            'body' => 'The Annual Sports Day will be held on the school ground on Friday. All students must attend in sports uniform.',
            'audience' => 'all', 'priority' => 'normal',
            'published_by' => $this->admin()->id, 'is_published' => true,
        ]);
        $this->actingAs($this->admin())
            ->from(route('settings'))
            ->post(route('settings.knowledge.rebuild'))
            ->assertRedirect(route('settings'))
            ->assertSessionHas('flash_success');
        $this->assertTrue(
            RagChunk::where('source_type', 'circular')->where('source_id', $circular->id)->exists()
        );

        $this->actingAs($this->parent())
            ->from(route('ask.index'))
            ->post(route('ask.ask'), ['question' => 'When is the Annual Sports Day?'])
            ->assertRedirect(route('ask.index'))
            ->assertSessionHas('rag_refused', false);
        // Mock answer quotes the chunk's best-matching sentence: the
        // title + first sentence ("held on the school ground"), not
        // the second sentence ("sports uniform") — retrieval + citation
        // are the proven legs here, not sentence choice.
        $this->assertStringContainsString('school ground', session('rag_answer'));
        $sources = session('rag_sources', []);
        $this->assertSame('Circular', $sources[0]['label']);
        $this->assertSame('G3 Sports Day Circular', $sources[0]['title']);
    }
}
