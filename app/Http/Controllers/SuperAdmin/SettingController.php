<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Helpers\Qs;
use App\Http\Controllers\Controller;
use App\Http\Requests\SettingUpdate;
use App\Repositories\MyClassRepo;
use App\Repositories\SettingRepo;

class SettingController extends Controller
{
    /*
     * Settings field ownership (Workstream CM — owner ruling on the
     * SA/Admin boundary). The single settings endpoint is split by
     * FIELD, not by page:
     *   - SCHOOL_FIELDS: the school's own identity/operations — writable
     *     by the school Admin; the Super Admin views them read-only.
     *   - PLATFORM_FIELDS: platform-level keys — writable by the Super
     *     Admin only. Any future platform key MUST be listed here,
     *     because an unlisted key defaults to school-owned. (First
     *     platform key: google_site_verification, Workstream DG.)
     * A save that reaches across the boundary is refused whole.
     */
    public const SCHOOL_FIELDS = [
        'system_name', 'system_title', 'current_session', 'address',
        'phone', 'system_email', 'alt_email', 'term_begins', 'term_ends',
        'principal_name', 'logo', 'school_stamp',
        'principal_signature',
        'adm_no_format', 'adm_no_serial_start', 'adm_no_serial_width',
        'adm_no_custom_pattern',
    ];

    public const PLATFORM_FIELDS = [
        // Workstream DG: Google Search Console ownership token for the
        // public sign-in page. Platform-level (the domain belongs to the
        // platform, not to any one school), so the Super Admin owns it.
        'google_site_verification',
    ];

    /*
     * Agent G round 1 (F-G1-3): settings.update previously wrote EVERY
     * posted key (SettingRepo::update is updateOrCreate), so a crafted
     * save could create arbitrary settings rows — including the
     * timetable generator presets (tt_gen_primary/secondary), which
     * SchoolLevel::loadConfig silently falls back to defaults on bad
     * JSON. The endpoint now accepts only known keys: the school
     * fields, the platform fields, the school form's operational keys
     * (gateway, AI drafting section as posted by the main form,
     * teaching-load/library caps) and the dynamic next_term_fees_*
     * keys. Anything else is refused whole, same shape as the
     * field-ownership refusal. NOTE: ai_* stay writable here because
     * the main school form posts them (F-G1-1 design conflict — the
     * DH card's "platform-owned" claim vs the main form — is an owner
     * call, not silently changed by this list).
     */
    public const ALLOWED_FIELDS = [
        'system_name', 'system_title', 'current_session', 'address',
        'phone', 'system_email', 'alt_email', 'term_begins', 'term_ends',
        'principal_name', 'logo', 'school_stamp',
        'principal_signature',
        'google_site_verification',
        'gateway_enabled', 'razorpay_key_id', 'razorpay_key_secret',
        'razorpay_webhook_secret',
        'ai_enabled', 'ai_provider', 'ai_model', 'ai_api_key',
        'teacher_max_periods_day', 'teacher_max_periods_week',
        'library_fine_per_day',
        'adm_no_format', 'adm_no_serial_start', 'adm_no_serial_width',
        'adm_no_custom_pattern',
        'paid_leave_quota_days', 'leave_deduction_percent',
    ];

    protected $setting, $my_class;

    public function __construct(SettingRepo $setting, MyClassRepo $my_class)
    {
        $this->setting = $setting;
        $this->my_class = $my_class;
    }

    public function index()
    {
         $s = $this->setting->all();
         $d['class_types'] = $this->my_class->getTypes();
         $d['s'] = $s->flatMap(function($s){
            return [$s->type => $s->description];
        });

        /* Workstream DH: the Knowledge Base card (AI Assistant +
           policy documents + index state) renders below the school
           settings form on this same page. */
        $d['rag_policies'] = \App\Models\RagDocument::policies()->withCount('chunks')->latest()->get();
        $d['rag_stats'] = [
            'documents' => \App\Models\RagDocument::count(),
            'chunks' => \App\Models\RagChunk::count(),
        ];

        /* Settings lock state for the lock card on the page. */
        $d['settings_locked'] = \App\Support\SettingsLock::isLocked();
        $d['settings_open_request'] = \App\Support\SettingsLock::openRequest();

        return view('pages.super_admin.settings', $d);
    }

    public function update(SettingUpdate $req)
    {
        /* Settings lock (owner order 2026-10-29): once the Admin
           locks the school settings, no settings save lands until
           a written unlock request has been approved by the
           Principal and then the Super Admin. The Super Admin's
           own platform keys are not the school lock's business. */
        if (\App\Support\SettingsLock::isLocked() && !Qs::userIsSuperAdmin()) {
            return back()->with('flash_danger', \App\Support\SettingsLock::LOCKED_MESSAGE);
        }
        /* Field-ownership split (see the constants above): an Admin's
           save may not carry platform keys; a Super Admin's save may
           not carry school keys (SA reads school settings, the school
           Admin writes them). */
        $posted = array_keys($req->except('_token', '_method', 'school_stamp_remove', 'principal_signature_remove'));
        if ($req->hasFile('logo')) {
            $posted[] = 'logo';
        }
        if ($req->hasFile('school_stamp') || $req->filled('school_stamp_remove')) {
            $posted[] = 'school_stamp';
        }
        if ($req->hasFile('principal_signature') || $req->filled('principal_signature_remove')) {
            $posted[] = 'principal_signature';
        }
        $platformPosted = array_intersect($posted, self::PLATFORM_FIELDS);
        $schoolPosted = array_diff($posted, self::PLATFORM_FIELDS);

        if ((Qs::userIsAdmin() && $platformPosted) || (Qs::userIsSuperAdmin() && $schoolPosted)) {
            return back()->with('flash_danger', __('msg.denied'));
        }

        /* Agent G round 1 (F-G1-3): refuse unknown keys whole — the
           endpoint must not mint arbitrary settings rows. */
        $unknown = array_filter($posted, function ($k) {
            return !in_array($k, self::ALLOWED_FIELDS, true)
                && strpos($k, 'next_term_fees_') !== 0;
        });
        if ($unknown) {
            return back()->with('flash_danger', __('msg.denied'));
        }

        $sets = $req->except('_token', '_method', 'logo', 'school_stamp', 'school_stamp_remove', 'principal_signature', 'principal_signature_remove');

        /* Workstream CU: gateway secrets are write-only in the form
           (the stored value is never rendered back), so a blank field
           means "keep what is saved", never "wipe it". Same for the
           key id — an accidental empty submit must not break a live
           gateway configuration. */
        foreach (['razorpay_key_secret', 'razorpay_webhook_secret', 'razorpay_key_id'] as $secret_key) {
            if (array_key_exists($secret_key, $sets) && trim((string) $sets[$secret_key]) === '') {
                unset($sets[$secret_key]);
            }
        }

        /* Workstream CX: the AI API key is write-only. The form renders
           the field empty (with a "key saved" hint), so a blank
           submission means "keep the stored key" — only a non-empty
           value replaces it. The key is never sent back to any blade. */
        if (array_key_exists('ai_api_key', $sets)) {
            if ($sets['ai_api_key'] === null || trim((string) $sets['ai_api_key']) === '') {
                unset($sets['ai_api_key']);
            } else {
                $sets['ai_api_key'] = trim((string) $sets['ai_api_key']);
            }
        }
        if (isset($sets['ai_enabled'])) {
            $sets['ai_enabled'] = $sets['ai_enabled'] == 1 ? 1 : 0;
        }
        $keys = array_keys($sets);
        $values = array_values($sets);
        for($i=0; $i<count($sets); $i++){
            $this->setting->update($keys[$i], $values[$i]);
        }

        if($req->hasFile('logo')) {
            $logo = $req->file('logo');
            $f = Qs::getFileMetaData($logo);
            /* Loop 106: Qs::getFileMetaData clamps extensions to the
               raster set every other upload field uses (Wave 8 stored
               -XSS guard — untouched). The logo additionally accepts a
               genuinely-sniffed SVG (rendered via <img> only); keyed
               off the file's real MIME type, never its name. */
            if ($logo->getMimeType() === 'image/svg+xml') {
                $f['ext'] = 'svg';
            }
            $f['name'] = 'logo.' . $f['ext'];
            $f['path'] = $logo->storeAs(Qs::getPublicUploadPath(), $f['name']);
            $logo_path = asset('storage/' . $f['path']);
            $this->setting->update('logo', $logo_path);
        }

        /* Batch M3: the school stamp follows the exact logo path —
           uploaded file -> public uploads -> settings row holding the
           asset URL. The report card renders it only while the
           setting is non-empty; the form's remove checkbox (or a
           fresh upload) is the only way it changes. */
        if($req->hasFile('school_stamp')) {
            $stamp = $req->file('school_stamp');
            $f = Qs::getFileMetaData($stamp);
            $f['name'] = 'school_stamp.' . $f['ext'];
            $f['path'] = $stamp->storeAs(Qs::getPublicUploadPath(), $f['name']);
            $stamp_path = asset('storage/' . $f['path']);
            $this->setting->update('school_stamp', $stamp_path);
        }
        elseif($req->filled('school_stamp_remove')) {
            $this->setting->update('school_stamp', '');
        }

        /* Digital signature (owner ruling 2026-10-22): the principal's
           signature image follows the exact school_stamp path — sniffed
           upload (Qs::getFileMetaData) -> public uploads -> settings row
           holding the asset URL. Report cards and admit cards render it
           above the Principal's signature line only while the setting
           is non-empty; the remove checkbox (or a fresh upload) is the
           only way it changes. */
        if($req->hasFile('principal_signature')) {
            $signature = $req->file('principal_signature');
            $f = Qs::getFileMetaData($signature);
            $f['name'] = 'principal_signature.' . $f['ext'];
            $f['path'] = $signature->storeAs(Qs::getPublicUploadPath(), $f['name']);
            $signature_path = asset('storage/' . $f['path']);
            $this->setting->update('principal_signature', $signature_path);
        }
        elseif($req->filled('principal_signature_remove')) {
            $this->setting->update('principal_signature', '');
        }

        return back()->with('flash_success', __('msg.update_ok'));

    }
}
