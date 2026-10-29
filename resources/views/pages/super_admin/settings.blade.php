@extends('layouts.master')
@section('page_title', 'Manage System Settings')
@section('content')

    {{-- Batch 5B: rebuilt on the shared form_page pattern (Loop 28).
         Same form contract as the legacy two-column layout — ONE PUT form
         to settings.update, multipart for the logo, every field name/id,
         required flag, select logic and error hook byte-equivalent.
         The teal italic hints are now plain muted helper text. --}}
    {{-- Workstream CM: school settings are written by the school
         Admin. The Super Admin views them read-only — the fieldset
         disables every input/select/button in the form, and
         SettingController refuses SA saves server-side as well. --}}
    @if(Qs::userIsSuperAdmin())
    <div class="alert alert-info border-0">You are viewing the school's settings read-only. The school Admin manages these; platform administration lives on the Platform Overview.</div>
    <fieldset disabled="disabled">
    @endif

    {{-- Settings lock (owner order 2026-10-29): the Admin locks the
         school's configuration when it is final; editing reopens
         only through a written request the Principal approves and
         the Super Admin finally approves. --}}
    @if(!Qs::userIsSuperAdmin())
        @if(!empty($settings_locked))
            <div class="alert alert-warning border-0">
                <strong><i class="icon-lock2 mr-1"></i> Settings are locked.</strong>
                Editing is closed. To make changes, send a written unlock request — the Principal will review it first, then the Super Admin takes the final decision. You will be notified when it is decided.
                @if(!empty($settings_open_request))
                    <div class="mt-2"><strong>Your request is {{ $settings_open_request->statusLabel() }}:</strong> “{{ $settings_open_request->changes }}”</div>
                @else
                    <form method="post" action="{{ route('settings.unlock_request') }}" class="mt-2">
                        @csrf
                        <div class="form-group">
                            <label for="unlock_changes">What changes do you want to make, and why? <span class="text-danger">*</span></label>
                            <textarea id="unlock_changes" name="changes" class="form-control" rows="3" maxlength="2000" required placeholder="e.g. Change the school phone number and the current session dates — the office number changed and the term starts a week earlier."></textarea>
                        </div>
                        <button class="btn btn-warning"><i class="icon-paperplane mr-1"></i> Send unlock request to the Principal</button>
                    </form>
                @endif
            </div>
            <fieldset disabled="disabled">
        @else
            <div class="alert alert-light border">
                <strong><i class="icon-unlocked2 mr-1"></i> Settings are open for editing.</strong>
                When the school's configuration is final, lock it — afterwards, changes need a written request approved by the Principal and the Super Admin.
                <form method="post" action="{{ route('settings.lock') }}" class="mt-2" data-confirm="Lock the settings? Editing will close until an unlock request is approved by the Principal and the Super Admin." data-confirm-title="Lock Settings" data-confirm-action="Lock settings">
                    @csrf
                    <button class="btn btn-primary btn-sm"><i class="icon-lock2 mr-1"></i> Lock settings</button>
                </form>
            </div>
        @endif
    @endif
    @component('partials.patterns.form_page', [
        'form_action'  => route('settings.update'),
        'form_method'  => 'PUT',
        'form_enctype' => 'multipart/form-data',
        'submit_label' => 'Save Settings',
    ])
        @slot('title')
            Update System Settings
        @endslot
        @slot('subtitle')
            School profile, calendar and fees for the whole school.
        @endslot

        {{-- ======================= School Profile ======================= --}}
        <div class="pat-form-section">
            <div class="pat-form-section-title">School Profile</div>
            <div class="pat-form-section-sub">The school's identity, shown across the app and on printed documents.</div>
            <div class="pat-fields">
                <div class="pat-field">
                    <label for="system_name">Name of School <span class="text-danger">*</span></label>
                    <input name="system_name" id="system_name" value="{{ $s['system_name'] }}" required type="text" class="form-control @error('system_name') is-invalid @enderror" placeholder="Name of School">
                    @include('partials.errors.field', ['field' => 'system_name'])
                </div>
                <div class="pat-field">
                    <label for="current_session">Current Session <span class="text-danger">*</span></label>
                    <select data-placeholder="Choose..." required name="current_session" id="current_session" class="select-search form-control @error('current_session') is-invalid @enderror">
                        <option value=""></option>
                        @php $sessionInRange = false; @endphp
                        @for($y=date('Y', strtotime('- 3 years')); $y<=date('Y', strtotime('+ 1 years')); $y++)
                            @php $sessionLabel = ($y-1).'-'.$y; if($sessionLabel === $s['current_session']) $sessionInRange = true; @endphp
                            <option value="{{ $sessionLabel }}" {{ $s['current_session'] == $sessionLabel ? 'selected' : '' }}>{{ $sessionLabel }}</option>
                        @endfor
                        @unless($sessionInRange)
                            <option value="{{ $s['current_session'] }}" selected>{{ $s['current_session'] }} (current)</option>
                        @endunless
                    </select>
                    @include('partials.errors.field', ['field' => 'current_session'])
                </div>
                <div class="pat-field">
                    <label for="system_title">School Acronym</label>
                    <input name="system_title" id="system_title" value="{{ $s['system_title'] }}" type="text" class="form-control @error('system_title') is-invalid @enderror" placeholder="School Acronym">
                    @include('partials.errors.field', ['field' => 'system_title'])
                </div>
                <div class="pat-field">
                    <label for="phone">Phone</label>
                    <input name="phone" id="phone" value="{{ $s['phone'] }}" type="text" class="form-control @error('phone') is-invalid @enderror" placeholder="e.g. 98xxxxxx01">
                    @include('partials.errors.field', ['field' => 'phone'])
                </div>
                <div class="pat-field">
                    <label for="system_email">School Email</label>
                    <input name="system_email" id="system_email" value="{{ $s['system_email'] }}" type="email" class="form-control @error('system_email') is-invalid @enderror" placeholder="School Email">
                    @include('partials.errors.field', ['field' => 'system_email'])
                </div>
                <div class="pat-field pat-field-wide">
                    <label for="address">School Address <span class="text-danger">*</span></label>
                    <input required name="address" id="address" value="{{ $s['address'] }}" type="text" class="form-control @error('address') is-invalid @enderror" placeholder="School Address">
                    @include('partials.errors.field', ['field' => 'address'])
                </div>
                <div class="pat-field pat-field-wide">
                    <label for="logo">School Logo</label>
                    <div class="mb-2">
                        <img style="max-width: 140px" src="{{ Qs::getLogo() }}" alt="School logo">
                    </div>
                    <input name="logo" id="logo" accept="image/*" type="file" class="file-input @error('logo') is-invalid @enderror" data-show-caption="false" data-show-upload="false" data-fouc>
                    @include('partials.errors.field', ['field' => 'logo'])
                    <small class="form-text text-muted">Accepted Images: jpeg, jpg, png, gif, webp. Max file size 2Mb</small>
                    @if($s['logo'])
                        <small class="form-text text-muted">This logo appears across the sidebar, login page and printed documents.</small>
                    @else
                        <small class="form-text text-muted">Default logo shown — upload a file here to use your school's own logo across the sidebar, login page and printed documents.</small>
                    @endif
                </div>
            </div>
        </div>

        {{-- ======================= Admission Numbers ======================= --}}
        <div class="pat-form-section">
            <div class="pat-form-section-title">Admission Numbers</div>
            <div class="pat-form-section-sub">Every new student gets an admission number automatically. Just pick how it should look — the green example below shows exactly what new students will get. It applies everywhere: the Admit Student form, Quick Admit and bulk import.</div>
            <div class="pat-fields">
                <div class="pat-field">
                    <label for="adm_no_format">How should the number look?</label>
                    <select name="adm_no_format" id="adm_no_format" onchange="toggleAdmCustom(this.value)" class="form-control @error('adm_no_format') is-invalid @enderror">
                        <option value="code_class_year_serial" {{ ($s['adm_no_format'] ?? 'code_class_year_serial') === 'code_class_year_serial' ? 'selected' : '' }}>School code / Class / Year / Serial (e.g. HA/PN/2026/1001)</option>
                        <option value="code_year_serial" {{ ($s['adm_no_format'] ?? '') === 'code_year_serial' ? 'selected' : '' }}>School code / Year / Serial (e.g. HA/2026/1001)</option>
                        <option value="class_year_serial" {{ ($s['adm_no_format'] ?? '') === 'class_year_serial' ? 'selected' : '' }}>Class / Year / Serial (e.g. PN/2026/1001)</option>
                        <option value="code_serial" {{ ($s['adm_no_format'] ?? '') === 'code_serial' ? 'selected' : '' }}>School code / Serial (e.g. HA/1001)</option>
                        <option value="year_serial" {{ ($s['adm_no_format'] ?? '') === 'year_serial' ? 'selected' : '' }}>Year / Serial (e.g. 2026/1001)</option>
                        <option value="custom" {{ ($s['adm_no_format'] ?? '') === 'custom' ? 'selected' : '' }}>Custom — define your own pattern</option>
                    </select>
                    @include('partials.errors.field', ['field' => 'adm_no_format'])
                    <small class="form-text text-muted">Old students keep their old numbers — only new students get the new style.</small>
                </div>
                <div class="pat-field" id="adm_no_custom_wrap" style="{{ ($s['adm_no_format'] ?? '') === 'custom' ? '' : 'display:none' }}">
                    <label for="adm_no_custom_pattern">Custom Pattern</label>
                    <input name="adm_no_custom_pattern" id="adm_no_custom_pattern" value="{{ $s['adm_no_custom_pattern'] ?? '' }}" type="text" maxlength="50" class="form-control @error('adm_no_custom_pattern') is-invalid @enderror" placeholder="e.g. HA-{YEAR}-{CLASS}-" {{ ($s['adm_no_format'] ?? '') === 'custom' ? '' : 'disabled' }}>
                    @include('partials.errors.field', ['field' => 'adm_no_custom_pattern'])
                    <small class="form-text text-muted">Write your own style: {CODE} = school code, {CLASS} = class, {YEAR} = year. End with / or - — the running number is added at the end by itself (e.g. HA-2026-PN-1001).</small>
                </div>
                <div class="pat-field">
                    <label for="adm_no_serial_start">Start counting from</label>
                    <input name="adm_no_serial_start" id="adm_no_serial_start" value="{{ $s['adm_no_serial_start'] ?? '' }}" type="number" min="1" max="999999" class="form-control @error('adm_no_serial_start') is-invalid @enderror" placeholder="1000">
                    @include('partials.errors.field', ['field' => 'adm_no_serial_start'])
                    <small class="form-text text-muted">The first student with a new style gets this number. After that it counts up by itself. Numbers already given to students never change.</small>
                </div>
                <div class="pat-field">
                    <label for="adm_no_serial_width">Zeros in front of the number?</label>
                    @php $admWidthSaved = (int) ($s['adm_no_serial_width'] ?? 0); @endphp
                    <select name="adm_no_serial_width" id="adm_no_serial_width" class="form-control @error('adm_no_serial_width') is-invalid @enderror">
                        <option value="0" {{ $admWidthSaved === 0 ? 'selected' : '' }}>No zeros — plain numbers (1, 2, 3…)</option>
                        <option value="2" {{ $admWidthSaved === 2 ? 'selected' : '' }}>2 digits — 01, 02, 03…</option>
                        <option value="3" {{ $admWidthSaved === 3 ? 'selected' : '' }}>3 digits — 001, 002, 003…</option>
                        <option value="4" {{ $admWidthSaved === 4 ? 'selected' : '' }}>4 digits — 0001, 0002, 0003…</option>
                        @if(!in_array($admWidthSaved, [0, 2, 3, 4], true))
                            <option value="{{ $admWidthSaved }}" selected>{{ $admWidthSaved }} digits</option>
                        @endif
                    </select>
                    @include('partials.errors.field', ['field' => 'adm_no_serial_width'])
                    <small class="form-text text-muted">Zeros make every number the same length, like on a printed roll-number list.</small>
                </div>
            </div>
            @php
                $admExStart = (int) ($s['adm_no_serial_start'] ?? 0);
                if ($admExStart < 1) { $admExStart = 1000; }
                $admExSerial = function ($n) use ($admWidthSaved) {
                    return $admWidthSaved > 0 ? str_pad((string) $n, $admWidthSaved, '0', STR_PAD_LEFT) : (string) $n;
                };
                $admExPrefix = \App\Helpers\Qs::admNoPrefix('PN', (int) date('Y'));
            @endphp
            <div class="alert alert-success mt-3 mb-0" role="alert" style="font-size: 14px;">
                <strong>Example:</strong> new students will get admission numbers like
                <strong id="admExample1" style="font-family: monospace;">{{ $admExPrefix }}{{ $admExSerial($admExStart) }}</strong>,
                then <strong id="admExample2" style="font-family: monospace;">{{ $admExPrefix }}{{ $admExSerial($admExStart + 1) }}</strong>,
                then <strong id="admExample3" style="font-family: monospace;">{{ $admExPrefix }}{{ $admExSerial($admExStart + 2) }}</strong> — counting up automatically.
            </div>
        </div>

        {{-- ===================== Academic Calendar ===================== --}}
        <div class="pat-form-section">
            <div class="pat-form-section-title">Academic Calendar</div>
            <div class="pat-form-section-sub">Key dates for the current term.</div>
            <div class="pat-fields">
                <div class="pat-field">
                    <label for="term_ends">This Term Ends</label>
                    <input name="term_ends" id="term_ends" value="{{ $s['term_ends'] }}" type="text" class="form-control date-pick @error('term_ends') is-invalid @enderror" placeholder="Date Term Ends">
                    @include('partials.errors.field', ['field' => 'term_ends'])
                    <small class="form-text text-muted">M-D-Y or M/D/Y format.</small>
                </div>
                <div class="pat-field">
                    <label for="term_begins">Next Term Begins</label>
                    <input name="term_begins" id="term_begins" value="{{ $s['term_begins'] }}" type="text" class="form-control date-pick @error('term_begins') is-invalid @enderror" placeholder="Date Next Term Begins">
                    @include('partials.errors.field', ['field' => 'term_begins'])
                    <small class="form-text text-muted">M-D-Y or M/D/Y format.</small>
                </div>
            </div>
        </div>

        {{-- ======================= Teaching Load & Library ======================= --}}
        <div class="pat-form-section">
            <div class="pat-form-section-title">Teaching Load &amp; Library</div>
            <div class="pat-form-section-sub">Limits the staffing and timetable engines respect, and the library's late-return fine. Leave blank to keep the built-in defaults (8 periods a day, 40 a week, ₹10 a day).</div>
            <div class="pat-fields">
                <div class="pat-field">
                    <label for="teacher_max_periods_day">Max Periods per Teacher — Day</label>
                    <input name="teacher_max_periods_day" id="teacher_max_periods_day" value="{{ $s['teacher_max_periods_day'] ?? '' }}" type="number" min="1" max="14" class="form-control @error('teacher_max_periods_day') is-invalid @enderror" placeholder="Default 8">
                    @include('partials.errors.field', ['field' => 'teacher_max_periods_day'])
                </div>
                <div class="pat-field">
                    <label for="teacher_max_periods_week">Max Periods per Teacher — Week</label>
                    <input name="teacher_max_periods_week" id="teacher_max_periods_week" value="{{ $s['teacher_max_periods_week'] ?? '' }}" type="number" min="1" max="70" class="form-control @error('teacher_max_periods_week') is-invalid @enderror" placeholder="Default 40">
                    @include('partials.errors.field', ['field' => 'teacher_max_periods_week'])
                </div>
                <div class="pat-field">
                    <label for="library_fine_per_day">Library Fine per Day Late (₹)</label>
                    <input name="library_fine_per_day" id="library_fine_per_day" value="{{ $s['library_fine_per_day'] ?? '' }}" type="number" min="0" max="1000" class="form-control @error('library_fine_per_day') is-invalid @enderror" placeholder="Default 10">
                    @include('partials.errors.field', ['field' => 'library_fine_per_day'])
                </div>
            </div>
        </div>

        {{-- ======================= Next Term Fees ======================= --}}
        <div class="pat-form-section">
            <div class="pat-form-section-title">Next Term Fees</div>
            <div class="pat-form-section-sub">Default fee for each class type next term.</div>
            <div class="pat-fields">
                @foreach($class_types as $ct)
                    <div class="pat-field">
                        <label for="next_term_fees_{{ strtolower($ct->code) }}">{{ $ct->name }}</label>
                        <input class="form-control @error('next_term_fees_'.strtolower($ct->code)) is-invalid @enderror" value="{{ $s['next_term_fees_'.strtolower($ct->code)] }}" name="next_term_fees_{{ strtolower($ct->code) }}" id="next_term_fees_{{ strtolower($ct->code) }}" placeholder="{{ $ct->name }}" type="text">
                        @include('partials.errors.field', ['field' => 'next_term_fees_'.strtolower($ct->code)])
                    </div>
                @endforeach
            </div>
        </div>

        {{-- ======================= Online Fee Payments (Workstream CU) ======================= --}}
        <div class="pat-form-section">
            <div class="pat-form-section-title">Online Fee Payments (Razorpay)</div>
            <div class="pat-form-section-sub">Let parents pay fees online from their fees page. Money lands in the school's Razorpay account; the payment, receipt and balances are recorded here automatically. Keys come from your Razorpay dashboard (Settings → API Keys) and the webhook secret from your Razorpay webhook settings. Point the Razorpay webhook to <code>{{ url('/webhooks/razorpay') }}</code> for the <code>payment.captured</code> and <code>payment.failed</code> events.</div>
            <div class="pat-fields">
                <div class="pat-field">
                    <label for="gateway_enabled">Online Payments</label>
                    <select class="form-control select @error('gateway_enabled') is-invalid @enderror" name="gateway_enabled" id="gateway_enabled">
                        <option {{ ($s['gateway_enabled'] ?? '0') == '1' ? 'selected' : '' }} value="1">Enabled</option>
                        <option {{ ($s['gateway_enabled'] ?? '0') == '1' ? '' : 'selected' }} value="0">Disabled</option>
                    </select>
                    @include('partials.errors.field', ['field' => 'gateway_enabled'])
                    <small class="form-text text-muted">When disabled (or no keys are saved), parents see no Pay Online option anywhere.</small>
                </div>
                <div class="pat-field">
                    <label for="razorpay_key_id">Razorpay Key ID</label>
                    <input name="razorpay_key_id" id="razorpay_key_id" value="{{ $s['razorpay_key_id'] ?? '' }}" type="text" class="form-control @error('razorpay_key_id') is-invalid @enderror" placeholder="rzp_live_… or rzp_test_…" autocomplete="off">
                    @include('partials.errors.field', ['field' => 'razorpay_key_id'])
                    <small class="form-text text-muted">Test keys (rzp_test_…) run in simulation mode — no real money moves. Leave blank to keep the saved value.</small>
                </div>
                <div class="pat-field">
                    <label for="razorpay_key_secret">Razorpay Key Secret</label>
                    <input name="razorpay_key_secret" id="razorpay_key_secret" value="" type="password" class="form-control @error('razorpay_key_secret') is-invalid @enderror" placeholder="{{ !empty($s['razorpay_key_secret']) ? '•••••••••• (saved — leave blank to keep)' : 'Not saved yet' }}" autocomplete="new-password">
                    @include('partials.errors.field', ['field' => 'razorpay_key_secret'])
                    <small class="form-text text-muted">Write-only: the saved secret is never shown. Type a new one only to replace it.</small>
                </div>
                <div class="pat-field">
                    <label for="razorpay_webhook_secret">Webhook Secret</label>
                    <input name="razorpay_webhook_secret" id="razorpay_webhook_secret" value="" type="password" class="form-control @error('razorpay_webhook_secret') is-invalid @enderror" placeholder="{{ !empty($s['razorpay_webhook_secret']) ? '•••••••••• (saved — leave blank to keep)' : 'Not saved yet' }}" autocomplete="new-password">
                    @include('partials.errors.field', ['field' => 'razorpay_webhook_secret'])
                    <small class="form-text text-muted">The secret you set on the Razorpay webhook. Used to verify every webhook call. Write-only, like the key secret.</small>
                </div>
            </div>
        </div>

        {{-- ======================= AI Assistant ======================= --}}
        {{-- Workstream CX: AI Assist v1. The API key is write-only — the
             field always renders empty; a saved key shows only a status
             hint, never any part of the key itself. --}}
        <div class="pat-form-section">
            <div class="pat-form-section-title">AI Assistant</div>
            <div class="pat-form-section-sub">Optional AI drafting help for circulars and report-card comments. AI only suggests text — a person always reviews and saves. The product works fully with this off.</div>
            <div class="pat-fields">
                <div class="pat-field">
                    <label for="ai_enabled">AI Assist</label>
                    <select class="form-control select @error('ai_enabled') is-invalid @enderror" name="ai_enabled" id="ai_enabled">
                        <option {{ ($s['ai_enabled'] ?? 0) ? 'selected' : '' }} value="1">On</option>
                        <option {{ ($s['ai_enabled'] ?? 0) ?: 'selected' }} value="0">Off</option>
                    </select>
                    @include('partials.errors.field', ['field' => 'ai_enabled'])
                </div>
                <div class="pat-field">
                    <label for="ai_provider">Provider</label>
                    <select class="form-control select @error('ai_provider') is-invalid @enderror" name="ai_provider" id="ai_provider">
                        <option value="gemini" {{ ($s['ai_provider'] ?? 'gemini') == 'gemini' ? 'selected' : '' }}>Google Gemini (free tier)</option>
                    </select>
                    @include('partials.errors.field', ['field' => 'ai_provider'])
                </div>
                <div class="pat-field">
                    <label for="ai_model">Model</label>
                    <input name="ai_model" id="ai_model" value="{{ $s['ai_model'] ?? 'gemini-2.5-flash' }}" type="text" class="form-control @error('ai_model') is-invalid @enderror" placeholder="gemini-2.5-flash">
                    @include('partials.errors.field', ['field' => 'ai_model'])
                    <small class="form-text text-muted">The Gemini model used for drafting. Change it here when a newer free-tier model arrives — no code change needed.</small>
                </div>
                <div class="pat-field">
                    <label for="ai_api_key">Gemini API Key</label>
                    <input name="ai_api_key" id="ai_api_key" value="" type="password" autocomplete="new-password" class="form-control @error('ai_api_key') is-invalid @enderror" placeholder="{{ !empty($s['ai_api_key']) ? 'Key saved — paste a new key only to replace it' : 'Paste your free Gemini API key' }}">
                    @include('partials.errors.field', ['field' => 'ai_api_key'])
                    <small class="form-text text-muted">
                        @if(!empty($s['ai_api_key']))
                            A key is saved. It is never shown here — leave this blank to keep it, or paste a new key to replace it.
                        @else
                            No key saved yet. Get a free key from Google AI Studio and paste it here. Until then, AI Assist runs in preview (sample draft) mode.
                        @endif
                    </small>
                </div>
            </div>
        </div>

        <style>
        /* Owner order 2026-10-29: fileinput pending-selection previews
           used to blow up the Report Card columns (full-size cards
           under a "No ... uploaded" note). Keep them small and tidy. */
        .tt-upload-previews .file-preview-frame { max-width: 230px; }
        .tt-upload-previews .file-preview-image { max-height: 90px; width: auto; }
        .tt-upload-previews .file-preview { margin-bottom: 6px; }
        .tt-upload-previews .file-footer-caption { font-size: 12px; }
        </style>
        {{-- ======================= Report Card ======================= --}}
        <div class="pat-form-section tt-upload-previews">
            <div class="pat-form-section-title">Report Card</div>
            <div class="pat-form-section-sub">The principal's name, digital signature and the school stamp printed on report cards and admit cards.</div>
            <div class="pat-fields">
                <div class="pat-field">
                    <label for="principal_name">Principal Name</label>
                    <input name="principal_name" id="principal_name" value="{{ $s['principal_name'] ?? '' }}" type="text" class="form-control @error('principal_name') is-invalid @enderror" placeholder="Name printed under the Principal's signature">
                    @include('partials.errors.field', ['field' => 'principal_name'])
                    <small class="form-text text-muted">Printed under the Principal's signature line on every report card. Leave blank to print the line only.</small>
                </div>
                <div class="pat-field">
                    <label for="principal_signature">Principal Signature (Digital)</label>
                    @if(!empty($s['principal_signature']))
                        <div class="mb-2">
                            <img style="max-height: 44px; max-width: 180px" src="{{ $s['principal_signature'] }}" alt="Principal signature">
                        </div>
                        <label class="d-block font-weight-normal">
                            <input type="checkbox" name="principal_signature_remove" value="1"> Remove this signature
                        </label>
                    @else
                        <div class="text-muted mb-2" id="principal_signature_empty_note">No signature uploaded — documents print with a blank Principal signature line.</div>
                        <div class="text-muted mb-2" id="principal_signature_pending_note" style="display:none">New signature selected — save settings to use it on documents.</div>
                    @endif
                    <input name="principal_signature" id="principal_signature" accept="image/*" type="file" class="file-input @error('principal_signature') is-invalid @enderror" data-show-caption="false" data-show-upload="false" data-fouc>
                    @include('partials.errors.field', ['field' => 'principal_signature'])
                    <small class="form-text text-muted">Printed above the Principal's signature line on report cards and admit cards, so generated documents come out digitally signed. A transparent PNG works best. Accepted Images: jpeg, jpg, png, gif, webp. Max file size 2Mb</small>
                </div>
                <div class="pat-field">
                    <label for="school_stamp">School Stamp</label>
                    @if(!empty($s['school_stamp']))
                        <div class="mb-2">
                            <img style="max-width: 110px" src="{{ $s['school_stamp'] }}" alt="School stamp">
                        </div>
                        <label class="d-block font-weight-normal">
                            <input type="checkbox" name="school_stamp_remove" value="1"> Remove this stamp
                        </label>
                    @else
                        <div class="text-muted mb-2" id="school_stamp_empty_note">No stamp uploaded — report cards print without one. A stamp appears on a card only when one is uploaded here; no placeholder is ever drawn.</div>
                        <div class="text-muted mb-2" id="school_stamp_pending_note" style="display:none">New stamp selected — save settings to use it on report cards.</div>
                    @endif
                    <input name="school_stamp" id="school_stamp" accept="image/*" type="file" class="file-input @error('school_stamp') is-invalid @enderror" data-show-caption="false" data-show-upload="false" data-fouc>
                    @include('partials.errors.field', ['field' => 'school_stamp'])
                    <small class="form-text text-muted">A transparent PNG or WebP of the school seal works best. Accepted Images: jpeg, jpg, png, gif, webp. Max file size 2Mb</small>
                </div>
            </div>
        </div>

    @endcomponent
    <script>
    function toggleAdmCustom(format) {
        var wrap = document.getElementById('adm_no_custom_wrap');
        var input = document.getElementById('adm_no_custom_pattern');
        if (!wrap || !input) { return; }
        var on = format === 'custom';
        wrap.style.display = on ? '' : 'none';
        input.disabled = !on;
        updateAdmExample();
    }
    /* Admission Numbers made plain (owner, 2026-10-29 night —
       "mujhe samajh nahi aa raha, school ko kaise aayega"): the
       green example under the fields recomputes live from the
       three controls, mirroring Qs::admNoPrefix + generateAdmNo
       exactly (segments joined with /, serial appended last,
       zero-padded to the chosen width). Sample class code PN
       and the current year, same as the format options show. */
    function updateAdmExample() {
        var fmt = document.getElementById('adm_no_format');
        var startEl = document.getElementById('adm_no_serial_start');
        var widthEl = document.getElementById('adm_no_serial_width');
        var patEl = document.getElementById('adm_no_custom_pattern');
        var out1 = document.getElementById('admExample1');
        if (!fmt || !out1) { return; }
        var code = '{{ strtoupper($s['system_title'] ?? 'HA') }}';
        var year = '{{ date('Y') }}';
        var prefix = '';
        if (fmt.value === 'custom') {
            prefix = (patEl && patEl.value ? patEl.value : 'HA-{YEAR}-{CLASS}-')
                .split('{CODE}').join(code).split('{CLASS}').join('PN').split('{YEAR}').join(year);
        } else {
            var segs = {
                code_class_year_serial: [code, 'PN', year],
                code_year_serial: [code, year],
                class_year_serial: ['PN', year],
                code_serial: [code],
                year_serial: [year]
            }[fmt.value] || [code, 'PN', year];
            prefix = segs.join('/') + '/';
        }
        var start = parseInt(startEl && startEl.value ? startEl.value : '1000', 10);
        if (!start || start < 1) { start = 1000; }
        var width = parseInt(widthEl && widthEl.value ? widthEl.value : '0', 10) || 0;
        var serial = function (n) {
            var s = String(n);
            while (width > 0 && s.length < width) { s = '0' + s; }
            return s;
        };
        out1.textContent = prefix + serial(start);
        document.getElementById('admExample2').textContent = prefix + serial(start + 1);
        document.getElementById('admExample3').textContent = prefix + serial(start + 2);
    }
    ['adm_no_format', 'adm_no_serial_start', 'adm_no_serial_width', 'adm_no_custom_pattern'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) { el.addEventListener('input', updateAdmExample); el.addEventListener('change', updateAdmExample); }
    });
    updateAdmExample();
    // Owner order 2026-10-29: the "No signature/stamp uploaded" note
    // describes the SAVED setting, but the file picker shows a preview
    // of the just-selected (not yet saved) file underneath it — so the
    // page claimed "no signature" while showing one. While a file is
    // picked, hide the saved-state note and show the pending note;
    // clearing the pick restores the saved-state note. The saved
    // image (when one exists) is untouched: it stays live until save.
    function syncUploadNote(inputId, noteId, pendingId) {
        var input = document.getElementById(inputId);
        var note = document.getElementById(noteId);
        var pending = document.getElementById(pendingId);
        if (!input || !note || !pending) { return; }
        var has = input.files && input.files.length > 0;
        note.style.display = has ? 'none' : '';
        pending.style.display = has ? '' : 'none';
    }
    document.addEventListener('DOMContentLoaded', function () {
        var pairs = [
            ['principal_signature', 'principal_signature_empty_note', 'principal_signature_pending_note'],
            ['school_stamp', 'school_stamp_empty_note', 'school_stamp_pending_note']
        ];
        pairs.forEach(function (pair) {
            var input = document.getElementById(pair[0]);
            if (!input) { return; }
            var sync = function () { syncUploadNote(pair[0], pair[1], pair[2]); };
            input.addEventListener('change', sync);
            if (window.jQuery) { window.jQuery(input).on('fileclear filecleared', sync); }
            sync();
        });
    });
    </script>
    @if(Qs::userIsSuperAdmin())
    </fieldset>
    @endif
    @if(!Qs::userIsSuperAdmin() && !empty($settings_locked))
    </fieldset>
    @endif

    {{-- ======================= Platform (Workstream DG) =======================
         Platform-owned keys live in their own form: the school form above is
         Admin-written (and read-only for the Super Admin), while this
         section is Super Admin only. SettingController splits saves by
         field — google_site_verification is in PLATFORM_FIELDS. --}}
    @if(Qs::userIsSuperAdmin())
    <div class="card mt-3">
        <div class="card-body">
            <form method="POST" action="{{ route('settings.update') }}">
                @csrf
                @method('PUT')
                <div class="pat-form-section">
                    <div class="pat-form-section-title">Platform</div>
                    <div class="pat-form-section-sub">Settings that belong to the Hackstra platform itself, not to any one school.</div>
                    <div class="pat-fields">
                        <div class="pat-field pat-field-wide">
                            <label for="google_site_verification">Google Search Console Verification</label>
                            <input name="google_site_verification" id="google_site_verification" value="{{ $s['google_site_verification'] ?? '' }}" type="text" class="form-control @error('google_site_verification') is-invalid @enderror" placeholder="Paste the content value of the Google verification meta tag" autocomplete="off" spellcheck="false">
                            @include('partials.errors.field', ['field' => 'google_site_verification'])
                            <small class="form-text text-muted">In Google Search Console, choose the "HTML tag" verification method and paste only the <code>content</code> value here. It is printed on the public sign-in page so Google can confirm this domain. Leave blank to remove the tag.</small>
                        </div>
                    </div>
                </div>
                <div class="text-right mt-2">
                    <button type="submit" class="btn btn-primary">Save Platform Settings</button>
                </div>
            </form>
        </div>
    </div>
    @endif

    {{--Settings Edit Ends--}}

    {{-- ======================= AI Assistant (Workstream DH) ======================= --}}
    {{-- The ai_* keys are platform configuration with their own endpoint:
         the Super Admin writes them (AiSettingsController), the school
         Admin sees this card read-only. The API key is write-only — the
         field always renders empty; a saved key shows only a hint. --}}
    <div class="card">
        <div class="card-header">
            <h5 class="card-title">AI Assistant</h5>
        </div>
        <div class="card-body">
            <p class="text-muted">Ask Hackstra answers school questions from the school's own documents — published circulars and the Knowledge Base below — with sources cited. It never reads student, marks or fee records. The product works fully with this off.</p>
            @if(!Qs::userIsSuperAdmin())
                <div class="alert alert-info border-0">AI configuration is managed by the platform (Super Admin). You can manage the Knowledge Base below and rebuild the index.</div>
            @endif
            <form method="POST" action="{{ route('settings.ai_update') }}">
                @csrf
                @method('PUT')
                <fieldset {{ Qs::userIsSuperAdmin() ? '' : 'disabled="disabled"' }}>
                    <div class="row">
                        <div class="form-group col-md-3">
                            <label for="ai_enabled_dh">AI Features</label>
                            <select class="form-control" name="ai_enabled" id="ai_enabled_dh">
                                <option {{ ($s['ai_enabled'] ?? 0) ? 'selected' : '' }} value="1">On</option>
                                <option {{ ($s['ai_enabled'] ?? 0) ?: 'selected' }} value="0">Off</option>
                            </select>
                        </div>
                        <div class="form-group col-md-3">
                            <label for="ai_provider_dh">Provider</label>
                            <select class="form-control" name="ai_provider" id="ai_provider_dh">
                                <option value="gemini" {{ ($s['ai_provider'] ?? 'gemini') == 'gemini' ? 'selected' : '' }}>Google Gemini (free tier)</option>
                            </select>
                        </div>
                        <div class="form-group col-md-3">
                            <label for="ai_model_dh">Answer Model</label>
                            <input name="ai_model" id="ai_model_dh" value="{{ $s['ai_model'] ?? 'gemini-2.5-flash' }}" type="text" class="form-control" placeholder="gemini-2.5-flash">
                        </div>
                        <div class="form-group col-md-3">
                            <label for="ai_embedding_model">Embedding Model</label>
                            <input name="ai_embedding_model" id="ai_embedding_model" value="{{ $s['ai_embedding_model'] ?? 'text-embedding-004' }}" type="text" class="form-control" placeholder="text-embedding-004">
                            <small class="form-text text-muted">Changing this re-embeds the whole index on the next rebuild.</small>
                        </div>
                        <div class="form-group col-md-6">
                            <label for="ai_api_key_dh">Gemini API Key</label>
                            <input name="ai_api_key" id="ai_api_key_dh" value="" type="password" autocomplete="new-password" class="form-control" placeholder="{{ !empty($s['ai_api_key']) ? 'Key saved — paste a new key only to replace it' : 'Paste your free Gemini API key' }}">
                            <small class="form-text text-muted">
                                @if(!empty($s['ai_api_key']))
                                    A key is saved. It is never shown here — leave this blank to keep it, or paste a new key to replace it.
                                @else
                                    No key saved yet. Get a free key from Google AI Studio and paste it here. Until then, AI runs in preview (deterministic sample) mode.
                                @endif
                            </small>
                        </div>
                    </div>
                    @if(Qs::userIsSuperAdmin())
                        <button type="submit" class="btn btn-primary">Save AI Settings</button>
                    @endif
                </fieldset>
            </form>
        </div>
    </div>

    {{-- ======================= Knowledge Base (Workstream DH) ======================= --}}
    <div class="card">
        <div class="card-header header-elements-inline">
            <h5 class="card-title">Knowledge Base — what Ask Hackstra can answer from</h5>
            <div class="header-elements">
                <form method="POST" action="{{ route('settings.knowledge.rebuild') }}" style="display:inline">
                    @csrf
                    <button type="submit" class="btn btn-primary btn-sm"><i class="icon-database-refresh mr-1"></i> Rebuild Knowledge Index</button>
                </form>
            </div>
        </div>
        <div class="card-body">
            <p class="text-muted mb-3">The index currently holds <strong>{{ $rag_stats['documents'] ?? 0 }}</strong> documents in <strong>{{ $rag_stats['chunks'] ?? 0 }}</strong> searchable pieces. Published circulars are indexed automatically on rebuild, keeping each circular's audience — a parents-only circular is never shown to anyone else, and staff-only material never reaches parents or students. Rebuilding is safe to repeat: unchanged documents are not re-processed.</p>

            <h6 class="font-weight-semibold">Add a school policy document</h6>
            <form method="POST" action="{{ route('settings.knowledge.store') }}" class="mb-4">
                @csrf
                <div class="form-group">
                    <label for="kb_title">Title</label>
                    <input name="title" id="kb_title" type="text" required maxlength="200" class="form-control" placeholder="e.g. Uniform Policy">
                </div>
                <div class="form-group">
                    <label for="kb_body">Policy text</label>
                    <textarea name="body" id="kb_body" required rows="5" class="form-control" placeholder="Paste the policy text here — Ask Hackstra will answer from exactly this text."></textarea>
                </div>
                <div class="form-check mb-2">
                    <label class="form-check-label">
                        <input type="checkbox" name="is_public" value="1" class="form-check-input">
                        Visible to parents and students too (default: staff only)
                    </label>
                </div>
                <button type="submit" class="btn btn-success btn-sm"><i class="icon-plus2 mr-1"></i> Add &amp; Index</button>
            </form>

            <h6 class="font-weight-semibold">Policy documents</h6>
            @forelse($rag_policies ?? [] as $doc)
                <div class="d-flex justify-content-between align-items-center border-bottom py-2">
                    <div>
                        <strong>{{ $doc->title }}</strong>
                        <span class="badge {{ $doc->is_public ? 'badge-success' : 'badge-light' }} ml-1">{{ $doc->is_public ? 'Everyone' : 'Staff only' }}</span>
                        <div class="text-muted">{{ $doc->chunks_count }} indexed piece(s) · updated {{ $doc->updated_at ? $doc->updated_at->format('d M Y') : '' }}</div>
                    </div>
                    <form method="POST" action="{{ route('settings.knowledge.destroy', $doc->id) }}" style="display:inline">
                        @csrf
                        @method('delete')
                        <button type="submit" class="btn btn-light btn-sm text-danger" data-confirm="Delete this policy document? It will stop being answerable immediately." data-confirm-title="Delete Policy" data-confirm-action="Delete" data-confirm-danger>Delete</button>
                    </form>
                </div>
            @empty
                <p class="text-muted mb-0">No policy documents yet. Add the first one above — admission rules, uniform policy, leave policy — whatever parents and staff ask the office about.</p>
            @endforelse
        </div>
    </div>

@endsection
