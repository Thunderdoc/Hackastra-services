<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Helpers\Qs;
use App\Http\Controllers\Controller;
use App\Repositories\SettingRepo;
use App\Support\Audit;
use Illuminate\Http\Request;

/**
 * AI platform settings (Workstream DH, following CX's conventions).
 *
 * The ai_* keys are PLATFORM configuration (the vendor's provider,
 * model and API key), so — under the settings field-ownership split —
 * they live behind their own endpoint instead of the school settings
 * form: the Super Admin writes them; the school Admin sees the card
 * read-only. The API key is write-only: the form renders it empty, a
 * blank submission keeps the stored key, and the key is never sent
 * back to any blade or log.
 */
class AiSettingsController extends Controller
{
    protected $setting;

    public function __construct(SettingRepo $setting)
    {
        $this->setting = $setting;
    }

    public function update(Request $req)
    {
        if (!Qs::userIsSuperAdmin()) {
            return back()->with('flash_danger', __('msg.denied'));
        }

        $data = $req->validate([
            'ai_enabled' => 'required|in:0,1',
            'ai_provider' => 'required|string|in:gemini',
            'ai_model' => 'nullable|string|max:100',
            'ai_embedding_model' => 'nullable|string|max:100',
            'ai_api_key' => 'nullable|string|max:255',
        ]);

        $this->setting->update('ai_enabled', $data['ai_enabled'] == 1 ? 1 : 0);
        $this->setting->update('ai_provider', $data['ai_provider']);
        $this->setting->update('ai_model', trim((string) ($data['ai_model'] ?? '')) ?: 'gemini-2.5-flash');
        $this->setting->update('ai_embedding_model', trim((string) ($data['ai_embedding_model'] ?? '')) ?: 'text-embedding-004');

        $keyChanged = false;
        if (array_key_exists('ai_api_key', $data) && $data['ai_api_key'] !== null && trim((string) $data['ai_api_key']) !== '') {
            $this->setting->update('ai_api_key', trim((string) $data['ai_api_key']));
            $keyChanged = true;
        }

        Audit::log('ai.settings_updated', null, 'AI settings updated (enabled: ' . ($data['ai_enabled'] == 1 ? 'on' : 'off') . ', model: ' . $data['ai_model'] . ', embedding model: ' . $data['ai_embedding_model'] . ', API key: ' . ($keyChanged ? 'replaced' : 'unchanged') . ')');

        return back()->with('flash_success', 'AI settings saved.');
    }
}
