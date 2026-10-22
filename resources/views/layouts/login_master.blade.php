<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    {{-- Workstream DG — public face of the sign-in page: a factual
         description for search engines and link previews, plus the
         Google Search Console ownership tag when the Super Admin has
         set one in Settings (platform key google_site_verification).
         Nothing is rendered for the tag while the setting is empty. --}}
    <meta name="description" content="Hackstra is a school management system — admissions, attendance, exams, fees, library and communication in one place. Sign in to continue.">
    <meta property="og:type" content="website">
    <meta property="og:title" content="{{ Qs::getSystemName() ?: 'Hackstra' }}">
    <meta property="og:description" content="Hackstra — school management system. Sign in to continue.">
    @if($gsv = Qs::getSetting('google_site_verification'))
    <meta name="google-site-verification" content="{{ $gsv }}">
    @endif

    {{-- Loop 50 (Workstream BX): guests have no account preference to
         render server-side, so honour this browser's mirror of the last
         signed-in choice (the topbar toggle keeps localStorage 'hk-theme'
         in sync with the account store). Runs pre-paint: no flash. --}}
    <script>try{if(localStorage.getItem('hk-theme')!=='light'){document.documentElement.classList.add('hk-dark');}}catch(e){}</script>

    <title>{{ Qs::getSystemName() ?: 'School Management System' }}</title>

    @include('partials.login.inc_top')
</head>

<body>
@include('partials.login.header')
@yield('content')
@include('partials.login.footer')

</body>

</html>
