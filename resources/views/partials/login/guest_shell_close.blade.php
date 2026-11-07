{{-- Guest shell CLOSE (Guest Calm Split): the form panel's powered
     line, the floating card close, and the under-card legal line —
     identical to auth/login. Pair with guest_shell_open. --}}
            <p class="login-a-powered">Powered by Hackastra</p>
        </div>
    </div>

    <p class="login-a-legal">
        <span class="login-a-legal-item">&copy; {{ date('Y') }} {{ Qs::getSystemName() ?: 'School Management System' }}</span>
        <span class="login-a-legal-sep">&middot;</span>
        <span class="login-a-legal-item"><a href="{{ route('privacy_policy') }}" target="_blank">Privacy Policy</a></span>
        <span class="login-a-legal-sep">&middot;</span>
        <span class="login-a-legal-item"><a href="{{ route('terms_of_use') }}" target="_blank">Terms of Use</a></span>
        <span class="login-a-legal-sep">&middot;</span>
        <span class="login-a-legal-item">Current session: {{ Qs::getCurrentSession() ?: '—' }}</span>
    </p>
</div>
