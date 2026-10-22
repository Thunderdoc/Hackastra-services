@extends('layouts.master')
@section('page_title', 'Send Platform Mail')
@section('content')
    <div class="card">
        <div class="card-header header-elements-inline">
            <span class="text-muted">Message platform accounts — a specific user, all School Admins, or everyone in a role</span>
            {!! Qs::getPanelOptions() !!}
        </div>
        <div class="card-body">
            @if($mail_note)
                <div class="alert alert-warning">{{ $mail_note }}</div>
            @endif

            <form method="post" action="{{ route('platform_mail.send') }}">
                @csrf

                <div class="form-group">
                    <label for="audience">Send to <span class="text-danger">*</span></label>
                    <select name="audience" id="audience" class="form-control">
                        <option value="user" {{ old('audience') === 'user' ? 'selected' : '' }}>A specific user</option>
                        <option value="admins" {{ old('audience') === 'admins' ? 'selected' : '' }}>All School Admins</option>
                        <option value="role" {{ old('audience') === 'role' ? 'selected' : '' }}>All users of a role</option>
                    </select>
                    @error('audience')<span class="text-danger">{{ $message }}</span>@enderror
                </div>

                <div class="form-group" id="audience-user">
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

                <div class="form-group" id="audience-role" style="display:none">
                    <label for="role">Role <span class="text-danger">*</span></label>
                    <select name="role" id="role" class="form-control">
                        <option value="">— Select a role —</option>
                        @foreach($user_types as $ut)
                            <option value="{{ $ut->title }}" {{ old('role') === $ut->title ? 'selected' : '' }}>All {{ $ut->name }}s</option>
                        @endforeach
                    </select>
                    @error('role')<span class="text-danger">{{ $message }}</span>@enderror
                </div>

                <div class="form-group">
                    <label for="subject">Subject <span class="text-danger">*</span></label>
                    <input type="text" name="subject" id="subject" class="form-control" maxlength="150" value="{{ old('subject') }}" required>
                    @error('subject')<span class="text-danger">{{ $message }}</span>@enderror
                </div>

                <div class="form-group">
                    <label for="body">Message <span class="text-danger">*</span></label>
                    <textarea name="body" id="body" class="form-control" rows="8" required>{{ old('body') }}</textarea>
                    @error('body')<span class="text-danger">{{ $message }}</span>@enderror
                </div>

                <button type="submit" class="btn btn-primary"><i class="icon-envelop5 mr-2"></i>Send Mail</button>
                <a href="{{ route('platform_mail.index') }}" class="btn btn-light ml-2">Sent Mail</a>
                <p class="text-muted mt-3 mb-0">Every send is recorded in Sent Mail and in the Audit Log. Accounts without an email address, and terminated accounts, are never mailed.</p>
            </form>
        </div>
    </div>
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
