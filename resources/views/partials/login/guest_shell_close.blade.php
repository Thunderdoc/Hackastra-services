{{-- Guest shell CLOSE (Guest Sample C): the form panel's powered
     line, the floating card close, and the under-card legal line —
     identical to auth/login. Pair with guest_shell_open. --}}
            <p class="login-a-powered">Powered by Hackastra</p>
        </div>
    </div>

    <p class="login-a-legal">
        &copy; {{ date('Y') }} {{ Qs::getSystemName() ?: 'School Management System' }}
        <span class="login-a-legal-sep">&middot;</span>
        <a href="{{ route('privacy_policy') }}" target="_blank">Privacy Policy</a>
        <span class="login-a-legal-sep">&middot;</span>
        <a href="{{ route('terms_of_use') }}" target="_blank">Terms of Use</a>
        <span class="login-a-legal-sep">&middot;</span>
        Current session: {{ Qs::getCurrentSession() ?: '—' }}
    </p>
</div>
