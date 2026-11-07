{{-- Card-top brand (Guest Sample C, 2026-11-06): the guest pages
     no longer carry a brand panel — the school identity lives at
     the top of the floating form card instead. Logo (or the app
     mark + code) plus the school name line, both from Settings,
     so a real school rebrands it from its own settings. Shared by
     auth/login and the guest shell (partials/login/guest_shell_open). --}}
<div class="login-a-brandmark">
    @if(Qs::getSetting('logo'))
        <img src="{{ Qs::getLogo() }}" alt="{{ Qs::getSystemName() ?: 'School logo' }}" class="login-a-logo">
    @else
        <span class="login-a-mark" aria-hidden="true">
            <svg viewBox="0 0 48 48" focusable="false">
                <defs>
                    <linearGradient id="login-a-lg" x1="0" y1="0" x2="1" y2="1">
                        <stop offset="0" stop-color="#3478f6"/>
                        <stop offset="1" stop-color="#2867dc"/>
                    </linearGradient>
                </defs>
                <path d="M24 2.8 42 13.1v21.8L24 45.2 6 34.9V13.1z" fill="url(#login-a-lg)"/>
                <path d="M24 12.4l3.4 7 7.7 1.1-5.6 5.4 1.4 7.6L24 29.9l-6.9 3.6 1.4-7.6-5.6-5.4 7.7-1.1z" fill="#fff"/>
            </svg>
        </span>
        <span class="login-a-wordmark">{{ Qs::getAppCode() }}</span>
    @endif
</div>
<p class="login-a-school-line">{{ Qs::getSystemName() ?: 'School Management System' }}</p>
