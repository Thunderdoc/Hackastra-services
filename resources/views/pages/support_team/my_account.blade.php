@extends('layouts.master')
@section('page_title', 'My Account')
{{-- This page renders its own designed header (ONE H1 + subtitle
     + profile cover), so the layout's generic title bar is
     suppressed — the title would otherwise print twice. No
     breadcrumb and no session chip here: the topbar owns the
     session chip, and a one-item breadcrumb is pure duplication
     (owner flagged both repeatedly; final design = Loop 43). --}}
@section('hide_page_header', true)
@section('content')

    @php
        $role_label = ucwords(str_replace('_', ' ', $my->user_type));
        $initial = strtoupper(substr($my->name, 0, 1));
        $date_fmt = $prefs['date_format'] ?? 'd M Y';
        $time_fmt = (($prefs['time_format'] ?? '12') === '24') ? 'H:i' : 'g:i A';
        $pass_errors = $errors->has('current_password') || $errors->has('password') || $errors->has('password_confirmation');
    @endphp

    <div class="acct-page">

        <h1 class="acct-title">My Account</h1>
        <p class="acct-subtitle">Manage your profile, security settings and school preferences.</p>

        @if(session('pop_error'))
            <div class="acct-alert acct-alert-danger"><i class="icon-info3"></i> {{ session('pop_error') }}</div>
        @endif

        {{-- Profile header: cover strip + overlapping avatar (Loop 43) --}}
        <div class="acct-profile-head">
            <div class="acct-cover" aria-hidden="true"></div>
            <div class="acct-profile-body">
                <div class="acct-avatar-wrap">
                    <span class="acct-avatar" aria-hidden="true">{{ $initial }}@if(\App\Helpers\Qs::hasRealPhoto(Auth::user()->photo))<img src="{{ Auth::user()->photo }}" alt="" onerror="this.remove()">@endif</span>
                    <button type="button" class="acct-avatar-btn" id="acct-photo-btn" title="Change profile photo" aria-label="Change profile photo"><i class="icon-camera"></i></button>
                </div>
                <div class="acct-banner-info">
                    <div class="acct-name-row">
                        <h2 class="acct-banner-name">{{ $my->name }}</h2>
                        <span class="acct-chip acct-chip-role">{{ $role_label }}</span>
                    </div>
                    <div class="acct-banner-meta">
                        @if($my->email)<span><i class="icon-envelop"></i> {{ $my->email }}</span>@endif
                        @if($my->phone)<span><i class="icon-phone"></i> {{ $my->phone }}</span>@endif
                        @if($my->created_at)<span><i class="icon-calendar"></i> Member since {{ $my->created_at->format($date_fmt) }}</span>@endif
                    </div>
                    @if($my->user_type === 'parent' && $children->count())
                        {{-- My Account v3: linked-children strip — real
                             children only, each card opens the same
                             child record page the My Children screen
                             links to (hashed record id). --}}
                        <div class="acct-children" aria-label="My children">
                            @foreach($children as $child)
                                @php $cu = $child->user; @endphp
                                <a class="acct-child" href="{{ route('students.show', \App\Helpers\Qs::hash($child->id)) }}">
                                    <span class="acct-avatar acct-avatar-sm" aria-hidden="true">{{ $cu ? strtoupper(substr($cu->name, 0, 1)) : '' }}@if($cu && \App\Helpers\Qs::hasRealPhoto($cu->photo))<img src="{{ $cu->photo }}" alt="" onerror="this.remove()">@endif</span>
                                    <span class="acct-child-text"><strong>{{ $cu->name ?? 'Student' }}</strong><small>{{ optional($child->my_class)->name }}@if($child->section) &middot; {{ $child->section->name }}@endif</small></span>
                                    <i class="icon-chevron-right acct-child-chev"></i>
                                </a>
                            @endforeach
                        </div>
                    @elseif(count($banner_chips))
                        <div class="acct-chips">
                            @foreach($banner_chips as $chip)
                                <span class="acct-chip">{{ $chip }}</span>
                            @endforeach
                        </div>
                    @endif
                </div>
                @if(count($banner_stats))
                    <div class="acct-banner-stats">
                        @foreach($banner_stats as $stat)
                            <div class="acct-stat">
                                <span class="acct-stat-icon"><i class="{{ $stat['icon'] }}"></i></span>
                                <span class="acct-stat-text"><small>{{ $stat['label'] }}</small><strong>{{ $stat['value'] }}</strong></span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        <div class="acct-grid">
                <div class="acct-card">
                    {{-- Tab bar --}}
                    <div class="acct-tabs" role="tablist" aria-label="Account sections">
                        <button type="button" class="acct-tab active" role="tab" aria-selected="true" data-acct-tab="profile"><i class="icon-user"></i> Profile Information</button>
                        <button type="button" class="acct-tab" role="tab" aria-selected="false" data-acct-tab="password"><i class="icon-lock"></i> Change Password</button>
                        <button type="button" class="acct-tab" role="tab" aria-selected="false" data-acct-tab="notifications"><i class="icon-bell2"></i> Notifications</button>
                        <button type="button" class="acct-tab" role="tab" aria-selected="false" data-acct-tab="preferences"><i class="icon-cog"></i> Preferences</button>
                    </div>

                    {{-- PROFILE --}}
                    <section class="acct-panel" id="acct-panel-profile" role="tabpanel">
                        <h3 class="acct-panel-title">Profile Information</h3>
                        <p class="acct-panel-sub">Update your personal and contact information.</p>

                        <form method="post" enctype="multipart/form-data" action="{{ route('my_account.update') }}" id="acct-profile-form">
                            @csrf @method('put')

                            <div class="acct-form-grid">
                                <div class="acct-field">
                                    <label for="acct-name">Full Name <span class="acct-req">*</span></label>
                                    <input id="acct-name" type="text" class="acct-input" value="{{ $my->name }}" disabled>
                                    <small class="acct-hint">Assigned by your school — contact the Admin to change it.</small>
                                </div>

                                <div class="acct-field">
                                    <label for="acct-email">Email Address <span class="acct-req">*</span></label>
                                    <div class="acct-input-icon"><i class="icon-envelop"></i><input id="acct-email" name="email" type="email" class="acct-input" value="{{ old('email', $my->email) }}"></div>
                                    @error('email')<small class="acct-error">{{ $message }}</small>@enderror
                                </div>

                                <div class="acct-field">
                                    <label for="acct-phone">Phone Number</label>
                                    <div class="acct-input-icon"><i class="icon-phone"></i><input id="acct-phone" name="phone" type="text" class="acct-input" value="{{ old('phone', $my->phone) }}"></div>
                                    @error('phone')<small class="acct-error">{{ $message }}</small>@enderror
                                </div>

                                <div class="acct-field">
                                    <label for="acct-phone2">Telephone</label>
                                    <div class="acct-input-icon"><i class="icon-phone"></i><input id="acct-phone2" name="phone2" type="text" class="acct-input" value="{{ old('phone2', $my->phone2) }}"></div>
                                    @error('phone2')<small class="acct-error">{{ $message }}</small>@enderror
                                </div>

                                @if($my->username)
                                    <div class="acct-field">
                                        <label for="acct-username">Username</label>
                                        <input id="acct-username" type="text" class="acct-input" value="{{ $my->username }}" disabled>
                                        <small class="acct-hint">Fixed once set — contact the Admin if it must change.</small>
                                    </div>
                                @else
                                    <div class="acct-field">
                                        <label for="acct-username">Username</label>
                                        <input id="acct-username" name="username" type="text" class="acct-input" value="{{ old('username') }}" placeholder="Choose a username">
                                        @error('username')<small class="acct-error">{{ $message }}</small>@enderror
                                    </div>
                                @endif

                                @if($my->gender)
                                    <div class="acct-field">
                                        <label for="acct-gender">Gender</label>
                                        <input id="acct-gender" type="text" class="acct-input" value="{{ ucfirst($my->gender) }}" disabled>
                                        <small class="acct-hint">Recorded by your school — contact the Admin to correct it.</small>
                                    </div>
                                @endif

                                <div class="acct-field">
                                    <label for="acct-code">Staff / Student Code</label>
                                    <input id="acct-code" type="text" class="acct-input" value="{{ $my->code }}" disabled>
                                    <small class="acct-hint">Assigned by your school — contact the Admin to change it.</small>
                                </div>

                                <div class="acct-field">
                                    <label for="acct-role">Role</label>
                                    <input id="acct-role" type="text" class="acct-input" value="{{ $role_label }}" disabled>
                                    <small class="acct-hint">Assigned by your school — contact the Admin to change it.</small>
                                </div>

                                @if($my->user_type === 'student' && $my->student_record)
                                    <div class="acct-field">
                                        <label for="acct-adm">Admission No</label>
                                        <input id="acct-adm" type="text" class="acct-input" value="{{ $my->student_record->adm_no }}" disabled>
                                        <small class="acct-hint">Assigned by your school — contact the Admin to change it.</small>
                                    </div>
                                @endif

                                <div class="acct-field acct-field-wide">
                                    <label for="acct-address">Address <span class="acct-req">*</span></label>
                                    <textarea id="acct-address" name="address" class="acct-input acct-textarea" rows="2">{{ old('address', $my->address) }}</textarea>
                                    @error('address')<small class="acct-error">{{ $message }}</small>@enderror
                                </div>

                                <div class="acct-field acct-field-wide">
                                    <label>Profile Photo</label>
                                    <div class="acct-photo-row">
                                        <span class="acct-avatar acct-avatar-md" aria-hidden="true">{{ $initial }}@if(\App\Helpers\Qs::hasRealPhoto(Auth::user()->photo))<img src="{{ Auth::user()->photo }}" alt="" onerror="this.remove()">@endif</span>
                                        <span class="acct-photo-preview" id="acct-photo-preview" hidden><img id="acct-photo-preview-img" src="" alt="Preview of the selected photo"><small>New photo</small></span>
                                        <div>
                                            <label for="acct-photo" class="acct-upload-btn"><i class="icon-upload"></i> Upload Photo</label>
                                            <input id="acct-photo" name="photo" type="file" accept="image/*" class="acct-file-input">
                                            <div class="acct-hint">JPG, PNG, GIF or WebP (Max 2MB)</div>
                                            <div class="acct-file-name" id="acct-photo-name"></div>
                                            @error('photo')<small class="acct-error">{{ $message }}</small>@enderror
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="acct-form-actions">
                                <button type="button" class="acct-btn acct-btn-ghost" id="acct-profile-cancel">Cancel</button>
                                <button type="submit" class="acct-btn acct-btn-primary"><i class="icon-checkmark2"></i> Save Changes</button>
                            </div>
                        </form>
                    </section>

                    {{-- PASSWORD --}}
                    <section class="acct-panel" id="acct-panel-password" role="tabpanel" hidden>
                        <h3 class="acct-panel-title">Change Password</h3>
                        <p class="acct-panel-sub">Update your account password regularly to keep your account secure.</p>

                        <form method="post" action="{{ route('my_account.change_pass') }}">
                            @csrf @method('put')

                            <div class="acct-form-grid">
                                <div class="acct-field acct-field-wide">
                                    <label for="acct-current-password">Current Password <span class="acct-req">*</span></label>
                                    <div class="acct-input-icon">
                                        <i class="icon-lock"></i>
                                        <input id="acct-current-password" name="current_password" type="password" class="acct-input" required autocomplete="current-password">
                                        <button type="button" class="acct-eye" data-acct-eye="acct-current-password" aria-label="Show or hide password"><i class="icon-eye"></i></button>
                                    </div>
                                    @error('current_password')<small class="acct-error">{{ $message }}</small>@enderror
                                </div>

                                <div class="acct-field">
                                    <label for="acct-new-password">New Password <span class="acct-req">*</span></label>
                                    <div class="acct-input-icon">
                                        <i class="icon-key"></i>
                                        <input id="acct-new-password" name="password" type="password" class="acct-input" required autocomplete="new-password">
                                        <button type="button" class="acct-eye" data-acct-eye="acct-new-password" aria-label="Show or hide password"><i class="icon-eye"></i></button>
                                    </div>
                                    <div class="acct-pw-meter" id="acct-pw-meter" hidden>
                                        <span class="acct-pw-track"><span class="acct-pw-fill" id="acct-pw-fill"></span></span>
                                        <small class="acct-pw-label" id="acct-pw-label"></small>
                                    </div>
                                    <small class="acct-hint">Minimum 8 characters, letters and numbers.</small>
                                    @error('password')<small class="acct-error">{{ $message }}</small>@enderror
                                </div>

                                <div class="acct-field">
                                    <label for="acct-confirm-password">Confirm New Password <span class="acct-req">*</span></label>
                                    <div class="acct-input-icon">
                                        <i class="icon-key"></i>
                                        <input id="acct-confirm-password" name="password_confirmation" type="password" class="acct-input" required autocomplete="new-password">
                                        <button type="button" class="acct-eye" data-acct-eye="acct-confirm-password" aria-label="Show or hide password"><i class="icon-eye"></i></button>
                                    </div>
                                    @error('password_confirmation')<small class="acct-error">{{ $message }}</small>@enderror
                                </div>
                            </div>

                            <div class="acct-form-actions">
                                <button type="submit" class="acct-btn acct-btn-primary"><i class="icon-lock"></i> Update Password</button>
                            </div>
                        </form>
                    </section>

                    {{-- NOTIFICATIONS --}}
                    <section class="acct-panel" id="acct-panel-notifications" role="tabpanel" hidden>
                        <h3 class="acct-panel-title">Notifications</h3>
                        <p class="acct-panel-sub">Choose which updates you want to receive.</p>

                        <form method="post" action="{{ route('my_account.preferences') }}">
                            @csrf
                            <input type="hidden" name="tab" value="notifications">

                            <div class="acct-toggle-list">
                                {{-- Owner order 2026-10-30: the proper mute —
                                     one master switch here in My Account,
                                     not just a hidden row in the bell menu.
                                     Muting silences the chime and greys the
                                     badge; notifications still collect in
                                     the list, nothing is ever dropped. --}}
                                <div class="acct-toggle-row">
                                    <span class="acct-toggle-icon acct-tint-rose"><i class="icon-volume-mute"></i></span>
                                    <span class="acct-toggle-text"><strong>Mute All Notifications</strong><small>Silences the bell sound and alert badge. Notifications still arrive in your list — nothing is lost.</small></span>
                                    <input type="hidden" name="notifications_muted_present" value="1">
                                    <label class="acct-switch"><input type="checkbox" name="notifications_muted" value="1" {{ ($prefs['notifications_muted'] ?? '0') === '1' ? 'checked' : '' }}><span></span></label>
                                </div>

                                <div class="acct-toggle-row">
                                    <span class="acct-toggle-icon acct-tint-blue"><i class="icon-volume-medium"></i></span>
                                    <span class="acct-toggle-text"><strong>Bell Sound</strong><small>A soft chime when you open the bell and something new is waiting.</small></span>
                                    <input type="hidden" name="bell_sound_present" value="1">
                                    <label class="acct-switch"><input type="checkbox" name="bell_sound" value="1" {{ ($prefs['bell_sound'] ?? '1') === '1' ? 'checked' : '' }}><span></span></label>
                                </div>

                                <div class="acct-toggle-row">
                                    <span class="acct-toggle-icon acct-tint-blue"><i class="icon-envelop"></i></span>
                                    <span class="acct-toggle-text"><strong>Email Notifications</strong><small>Receive important updates via email.</small></span>
                                    <input type="hidden" name="notify_email_present" value="1">
                                    <label class="acct-switch"><input type="checkbox" name="notify_email" value="1" {{ ($prefs['notify_email'] ?? '1') === '1' ? 'checked' : '' }}><span></span></label>
                                </div>

                                <div class="acct-toggle-row">
                                    <span class="acct-toggle-icon acct-tint-purple"><i class="icon-bell2"></i></span>
                                    <span class="acct-toggle-text"><strong>School Announcements</strong><small>New circulars and school announcements.</small></span>
                                    <input type="hidden" name="notify_announcements_present" value="1">
                                    <label class="acct-switch"><input type="checkbox" name="notify_announcements" value="1" {{ ($prefs['notify_announcements'] ?? '1') === '1' ? 'checked' : '' }}><span></span></label>
                                </div>

                                @if(in_array($my->user_type, ['super_admin', 'admin', 'teacher', 'student', 'parent'], true))
                                    <div class="acct-toggle-row">
                                        <span class="acct-toggle-icon acct-tint-green"><i class="icon-user-check"></i></span>
                                        <span class="acct-toggle-text"><strong>Attendance Reminders</strong><small>Reminders about attendance marking and reports.</small></span>
                                        <input type="hidden" name="notify_attendance_present" value="1">
                                        <label class="acct-switch"><input type="checkbox" name="notify_attendance" value="1" {{ ($prefs['notify_attendance'] ?? '1') === '1' ? 'checked' : '' }}><span></span></label>
                                    </div>
                                @endif

                                @if(in_array($my->user_type, ['teacher', 'student', 'parent'], true))
                                    <div class="acct-toggle-row">
                                        <span class="acct-toggle-icon acct-tint-orange"><i class="icon-book"></i></span>
                                        <span class="acct-toggle-text"><strong>Exam &amp; Marks Reminders</strong><small>Exam schedules and marks-entry deadlines.</small></span>
                                        <input type="hidden" name="notify_exams_present" value="1">
                                        <label class="acct-switch"><input type="checkbox" name="notify_exams" value="1" {{ ($prefs['notify_exams'] ?? '1') === '1' ? 'checked' : '' }}><span></span></label>
                                    </div>
                                @endif

                                @if(in_array($my->user_type, ['student', 'parent'], true))
                                    {{-- My Account v3: real switch — App\Support\Notify
                                         checks this preference before writing each
                                         LMS bell alert for this user. --}}
                                    <div class="acct-toggle-row">
                                        <span class="acct-toggle-icon acct-tint-blue"><i class="icon-bell2"></i></span>
                                        <span class="acct-toggle-text"><strong>Assignment &amp; Internal Marks Alerts</strong><small>Bell alerts when an assignment is graded or internal marks are published.</small></span>
                                        <input type="hidden" name="notify_lms_present" value="1">
                                        <label class="acct-switch"><input type="checkbox" name="notify_lms" value="1" {{ ($prefs['notify_lms'] ?? '1') === '1' ? 'checked' : '' }}><span></span></label>
                                    </div>
                                @endif
                            </div>

                            <div class="acct-form-actions">
                                <button type="submit" class="acct-btn acct-btn-primary"><i class="icon-checkmark2"></i> Save Preferences</button>
                            </div>
                        </form>
                    </section>

                    {{-- PREFERENCES --}}
                    <section class="acct-panel" id="acct-panel-preferences" role="tabpanel" hidden>
                        <h3 class="acct-panel-title">Preferences</h3>
                        <p class="acct-panel-sub">Display formats used for dates and times across your account pages.</p>

                        <form method="post" action="{{ route('my_account.preferences') }}">
                            @csrf
                            <input type="hidden" name="tab" value="preferences">

                            <div class="acct-form-grid">
                                <div class="acct-field">
                                    <label for="acct-date-format">Date Format</label>
                                    <select id="acct-date-format" name="date_format" class="acct-input">
                                        <option value="d M Y" {{ ($prefs['date_format'] ?? '') === 'd M Y' ? 'selected' : '' }}>21 Oct 2026</option>
                                        <option value="d/m/Y" {{ ($prefs['date_format'] ?? '') === 'd/m/Y' ? 'selected' : '' }}>21/10/2026</option>
                                        <option value="M d, Y" {{ ($prefs['date_format'] ?? '') === 'M d, Y' ? 'selected' : '' }}>Oct 21, 2026</option>
                                        <option value="Y-m-d" {{ ($prefs['date_format'] ?? '') === 'Y-m-d' ? 'selected' : '' }}>2026-10-21</option>
                                    </select>
                                </div>

                                <div class="acct-field">
                                    <label for="acct-time-format">Time Format</label>
                                    <select id="acct-time-format" name="time_format" class="acct-input">
                                        <option value="12" {{ ($prefs['time_format'] ?? '12') === '12' ? 'selected' : '' }}>12-hour (2:30 PM)</option>
                                        <option value="24" {{ ($prefs['time_format'] ?? '12') === '24' ? 'selected' : '' }}>24-hour (14:30)</option>
                                    </select>
                                </div>
                            </div>

                            <div class="acct-form-actions">
                                <button type="submit" class="acct-btn acct-btn-primary"><i class="icon-checkmark2"></i> Save Preferences</button>
                            </div>
                        </form>
                    </section>
                </div>


            {{-- Right rail --}}
            <aside class="acct-rail">
                <div class="acct-card acct-side-card">
                    <div class="acct-card-head">
                        <span class="acct-toggle-icon acct-tint-blue"><i class="icon-lock"></i></span>
                        <div>
                            <h3 class="acct-card-title">Account Security</h3>
                        </div>
                    </div>

                    <button type="button" class="acct-link-row" data-acct-goto="password">
                        <span class="acct-toggle-icon acct-tint-blue"><i class="icon-key"></i></span>
                        <span class="acct-toggle-text"><strong>Change Password</strong><small>Update your account password regularly.</small></span>
                        <i class="icon-chevron-right acct-row-chev"></i>
                    </button>

                    <a href="#acct-activity" class="acct-link-row">
                        <span class="acct-toggle-icon acct-tint-green"><i class="icon-history"></i></span>
                        <span class="acct-toggle-text"><strong>Sign-in Activity</strong><small>Review your recent sign-ins.</small></span>
                        <i class="icon-chevron-right acct-row-chev"></i>
                    </a>
                </div>

                <div class="acct-card acct-side-card">
                    <div class="acct-card-head">
                        <span class="acct-toggle-icon acct-tint-purple"><i class="icon-cog"></i></span>
                        <div>
                            <h3 class="acct-card-title">Preferences</h3>
                        </div>
                    </div>

                    {{-- Workstream CO: the rail card used to repeat the
                         Notifications panel's Email Notifications toggle
                         (same setting, same label, same page). Removed —
                         the Notifications tab owns notification settings;
                         this card now only navigates. --}}
                    <button type="button" class="acct-link-row" data-acct-goto="preferences">
                        <span class="acct-toggle-icon acct-tint-orange"><i class="icon-calendar"></i></span>
                        <span class="acct-toggle-text"><strong>Date &amp; Time Format</strong><small>{{ ($prefs['date_format'] ?? 'd M Y') }} &middot; {{ ($prefs['time_format'] ?? '12') === '24' ? '24-hour' : '12-hour' }}</small></span>
                        <i class="icon-chevron-right acct-row-chev"></i>
                    </button>
                </div>

                <div class="acct-card acct-side-card">
                    <div class="acct-card-head">
                        <span class="acct-toggle-icon acct-tint-green"><i class="icon-user"></i></span>
                        <div>
                            <h3 class="acct-card-title">Account Actions</h3>
                        </div>
                    </div>

                    <a href="{{ route('my_account.download') }}" class="acct-link-row">
                        <span class="acct-toggle-icon acct-tint-blue"><i class="icon-file-download"></i></span>
                        <span class="acct-toggle-text"><strong>Download My Data</strong><small>Export your profile, preferences and sign-ins as JSON.</small></span>
                        <i class="icon-chevron-right acct-row-chev"></i>
                    </a>

                    <form method="post" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="acct-link-row acct-signout">
                            <span class="acct-toggle-icon acct-tint-orange"><i class="icon-switch2"></i></span>
                            <span class="acct-toggle-text"><strong>Sign Out</strong><small>End your current session.</small></span>
                            <i class="icon-chevron-right acct-row-chev"></i>
                        </button>
                    </form>
                </div>

                {{-- Account activity — fourth card of the info band (Loop 48, Workstream BY;
                    was a full-width strip under the band until the one-screen pass) --}}
                <div class="acct-card acct-side-card acct-activity" id="acct-activity">
                    <div class="acct-card-head">
                    <span class="acct-toggle-icon acct-tint-blue"><i class="icon-history"></i></span>
                    <div>
                        <h3 class="acct-card-title">Account Activity</h3>
                        <p class="acct-panel-sub">Your recent sign-ins on this account.</p>
                    </div>
                    </div>

                    <div class="acct-activity-list">
                    @forelse($activity as $row)
                    <div class="acct-activity-row">
                        <span class="acct-activity-icon"><i class="icon-user-check"></i></span>
                        <span class="acct-activity-text"><strong>Signed in</strong><small>{{ $row->description ?: 'Successful sign-in' }}</small></span>
                        @if($row->ip_address)<span class="acct-ip">{{ $row->ip_address }}</span>@endif
                        <span class="acct-activity-when">{{ $row->created_at->format($date_fmt . ', ' . $time_fmt) }}</span>
                    </div>
                    @empty
                    <div class="acct-empty">
                        <i class="icon-history"></i>
                        <p>No sign-in activity recorded yet.</p>
                    </div>
                    @endforelse
                    </div>
                </div>
            </aside>
        </div>
    </div>

    {{--My Account Ends--}}

@endsection

@section('scripts')
<script>
    (function () {
        var tabs = Array.prototype.slice.call(document.querySelectorAll('[data-acct-tab]'));
        var panels = {
            profile: document.getElementById('acct-panel-profile'),
            password: document.getElementById('acct-panel-password'),
            notifications: document.getElementById('acct-panel-notifications'),
            preferences: document.getElementById('acct-panel-preferences')
        };

        function activate(name, pushHash) {
            if (!panels[name]) { name = 'profile'; }
            tabs.forEach(function (tab) {
                var on = tab.getAttribute('data-acct-tab') === name;
                tab.classList.toggle('active', on);
                tab.setAttribute('aria-selected', on ? 'true' : 'false');
            });
            Object.keys(panels).forEach(function (key) {
                if (panels[key]) { panels[key].hidden = key !== name; }
            });
            if (pushHash) {
                if (history.replaceState) { history.replaceState(null, '', '#' + name); }
                else { location.hash = name; }
            }
        }

        tabs.forEach(function (tab, idx) {
            tab.addEventListener('click', function () { activate(tab.getAttribute('data-acct-tab'), true); });
            tab.addEventListener('keydown', function (e) {
                var next = null;
                if (e.key === 'ArrowRight') { next = tabs[(idx + 1) % tabs.length]; }
                if (e.key === 'ArrowLeft') { next = tabs[(idx - 1 + tabs.length) % tabs.length]; }
                if (next) { e.preventDefault(); next.focus(); activate(next.getAttribute('data-acct-tab'), true); }
            });
        });

        document.querySelectorAll('[data-acct-goto]').forEach(function (el) {
            el.addEventListener('click', function () {
                activate(el.getAttribute('data-acct-goto'), true);
                window.scrollTo({ top: 0, behavior: 'smooth' });
            });
        });

        // Open the tab the URL points at; password errors always land on
        // the password tab so the failure is visible.
        var initial = (location.hash || '').replace('#', '');
        @if($pass_errors)
            initial = 'password';
        @endif
        activate(initial || 'profile', false);

        // Banner camera button: jump to the profile tab's photo picker.
        var photoBtn = document.getElementById('acct-photo-btn');
        var photoInput = document.getElementById('acct-photo');
        if (photoBtn && photoInput) {
            photoBtn.addEventListener('click', function () {
                activate('profile', true);
                photoInput.click();
            });
            photoInput.addEventListener('change', function () {
                var nameEl = document.getElementById('acct-photo-name');
                if (!photoInput.files || !photoInput.files.length) { return; }
                var file = photoInput.files[0];
                if (nameEl) {
                    nameEl.textContent = file.name + ' — press Save Changes to apply.';
                }
                // Loop 31 (Fix 7): live preview of the chosen image next
                // to the current avatar, before anything is saved.
                var wrap = document.getElementById('acct-photo-preview');
                var img = document.getElementById('acct-photo-preview-img');
                if (wrap && img && window.FileReader && /^image\//.test(file.type)) {
                    var reader = new FileReader();
                    reader.onload = function (e) {
                        img.src = e.target.result;
                        wrap.hidden = false;
                    };
                    reader.readAsDataURL(file);
                }
            });
        }

        // Cancel restores the stored values rendered into the form.
        var cancelBtn = document.getElementById('acct-profile-cancel');
        var profileForm = document.getElementById('acct-profile-form');
        if (cancelBtn && profileForm) {
            cancelBtn.addEventListener('click', function () { profileForm.reset(); });
        }

        // Show / hide password toggles.
        document.querySelectorAll('[data-acct-eye]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var input = document.getElementById(btn.getAttribute('data-acct-eye'));
                if (!input) { return; }
                var show = input.type === 'password';
                input.type = show ? 'text' : 'password';
                btn.querySelector('i').className = show ? 'icon-eye-blocked' : 'icon-eye';
            });
        });

        // My Account v3: password strength meter. Honest, explainable
        // rules only: length, then character classes. Advisory — the
        // server-side rules are unchanged and still decide.
        var pwInput = document.getElementById('acct-new-password');
        var pwMeter = document.getElementById('acct-pw-meter');
        var pwFill = document.getElementById('acct-pw-fill');
        var pwLabel = document.getElementById('acct-pw-label');
        if (pwInput && pwMeter && pwFill && pwLabel) {
            var pwWords = ['Weak', 'Weak', 'Fair', 'Good', 'Strong'];
            pwInput.addEventListener('input', function () {
                var v = pwInput.value;
                if (!v) { pwMeter.hidden = true; return; }
                var score = 0;
                if (v.length >= 8) { score++; }
                if (v.length >= 12) { score++; }
                if (/[a-z]/.test(v) && /[A-Z]/.test(v)) { score++; }
                if (/\d/.test(v)) { score++; }
                if (/[^A-Za-z0-9]/.test(v)) { score++; }
                if (score > 4) { score = 4; }
                pwMeter.hidden = false;
                pwFill.style.width = ((score + 1) * 20) + '%';
                pwFill.setAttribute('data-level', score);
                pwLabel.textContent = 'Strength: ' + pwWords[score];
            });
        }

        // My Account v3: unsaved-changes warning on the profile form.
        // Snapshot the rendered values; leaving the page (link, reload,
        // close) with different values — or a photo picked but not yet
        // saved — asks the browser's native confirm. Submitting or
        // cancelling clears the guard.
        var acctForm = document.getElementById('acct-profile-form');
        if (acctForm) {
            var acctSnapshot = null;
            var acctSubmitting = false;
            var captureAcct = function () {
                var data = {};
                new FormData(acctForm).forEach(function (value, key) {
                    if (typeof value === 'string') { data[key] = value; }
                });
                return data;
            };
            var acctDirty = function () {
                if (!acctSnapshot) { return false; }
                var now = captureAcct();
                for (var key in now) {
                    if (now[key] !== acctSnapshot[key]) { return true; }
                }
                var file = document.getElementById('acct-photo');
                return !!(file && file.files && file.files.length);
            };
            acctSnapshot = captureAcct();
            acctForm.addEventListener('submit', function () { acctSubmitting = true; });
            var acctCancel = document.getElementById('acct-profile-cancel');
            if (acctCancel) {
                acctCancel.addEventListener('click', function () {
                    setTimeout(function () { acctSnapshot = captureAcct(); }, 0);
                });
            }
            window.addEventListener('beforeunload', function (e) {
                if (!acctSubmitting && acctDirty()) {
                    e.preventDefault();
                    e.returnValue = '';
                }
            });
        }
    })();
</script>
@endsection
