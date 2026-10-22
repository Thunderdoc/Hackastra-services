@extends('layouts.master')
@section('page_title', 'Send Platform Mail')
@section('content')
    {{-- Loop 75: rebuilt on the shared form_page pattern (was a
         legacy stacked card form). Same fields, ids, POST route and
         audience-toggle script as before — presentation only.
         Loop 106: premium design scope (.l106-mail) — presentation
         only, same form pattern and fields. --}}
  <div class="l106 l106-mail">
    @if($mail_note)
        <div class="alert alert-warning">{{ $mail_note }}</div>
    @endif

    @component('partials.patterns.form_page', [
        'form_action' => route('platform_mail.send'),
    ])
        @slot('subtitle')
            Message platform accounts — a specific user, all School Admins, or everyone in a role
        @endslot

        <div class="pat-form-section">
            <div class="pat-form-section-title"><span class="pat-sec-icon"><i class="icon-users4"></i></span> Audience</div>
            <div class="pat-fields">
                <div class="pat-field">
                    <label for="audience">Send to <span class="text-danger">*</span></label>
                    <select name="audience" id="audience" class="form-control">
                        <option value="user" {{ old('audience') === 'user' ? 'selected' : '' }}>A specific user</option>
                        <option value="admins" {{ old('audience') === 'admins' ? 'selected' : '' }}>All School Admins</option>
                        <option value="role" {{ old('audience') === 'role' ? 'selected' : '' }}>All users of a role</option>
                    </select>
                    @error('audience')<span class="text-danger">{{ $message }}</span>@enderror
                </div>

                <div class="pat-field" id="audience-user">
                    <label for="user_id">Recipient user <span class="text-danger">*</span></label>
                    <select name="user_id" id="user_id" class="form-control">
                        <option value="">— Select a user —</option>
                        @foreach($accounts as $account)
                            <option value="{{ Qs::hash($account->id) }}" {{ old('user_id') === Qs::hash($account->id) ? 'selected' : '' }}>
                                {{ $account->name }} ({{ $account->email }}) — {{ ucwords(str_replace('_', ' ', $account->user_type)) }}
                            </option>
                        @endforeach
                    </select>
                    @error('user_id')<span class="text-danger">{{ $message }}</span>@enderror
                </div>

                <div class="pat-field" id="audience-role" style="display:none">
                    <label for="role">Role <span class="text-danger">*</span></label>
                    <select name="role" id="role" class="form-control">
                        <option value="">— Select a role —</option>
                        @foreach($user_types as $ut)
                            <option value="{{ $ut->title }}" {{ old('role') === $ut->title ? 'selected' : '' }}>All {{ $ut->name }}s</option>
                        @endforeach
                    </select>
                    @error('role')<span class="text-danger">{{ $message }}</span>@enderror
                </div>
            </div>
        </div>

        <div class="pat-form-section">
            <div class="pat-form-section-title"><span class="pat-sec-icon"><i class="icon-envelop5"></i></span> Message</div>
            <div class="pat-fields">
                <div class="pat-field pat-field-wide">
                    <label for="subject">Subject <span class="text-danger">*</span></label>
                    <input type="text" name="subject" id="subject" class="form-control" maxlength="150" value="{{ old('subject') }}" required>
                    @error('subject')<span class="text-danger">{{ $message }}</span>@enderror
                </div>

                <div class="pat-field pat-field-wide">
                    <label for="body">Message <span class="text-danger">*</span></label>
                    <textarea name="body" id="body" class="form-control" rows="8" required>{{ old('body') }}</textarea>
                    @error('body')<span class="text-danger">{{ $message }}</span>@enderror
                </div>
            </div>
        </div>

        <div class="pat-form-section">
            <p class="text-muted mb-0">Every send is recorded in Sent Mail and in the Audit Log. Accounts without an email address, and terminated accounts, are never mailed.</p>
        </div>

        @slot('actions')
            <a href="{{ route('platform_mail.index') }}" class="btn btn-light">Sent Mail</a>
            <button type="submit" class="btn btn-primary"><i class="icon-envelop5 mr-2"></i>Send Mail</button>
        @endslot
    @endcomponent
  </div>{{-- /.l106-mail --}}
@endsection

@section('scripts')
    <script>
        (function () {
            var audience = document.getElementById('audience');
            var userBox = document.getElementById('audience-user');
            var roleBox = document.getElementById('audience-role');
            function sync() {
                userBox.style.display = audience.value === 'user' ? '' : 'none';
                roleBox.style.display = audience.value === 'role' ? '' : 'none';
            }
            audience.addEventListener('change', sync);
            sync();
        })();
    </script>
@endsection
