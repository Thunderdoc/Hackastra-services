<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    {{-- Loop 50 (Workstream BX): guests have no account preference to
         render server-side, so honour this browser's mirror of the last
         signed-in choice (the topbar toggle keeps localStorage 'hk-theme'
         in sync with the account store). Runs pre-paint: no flash. --}}
    <script>try{if(localStorage.getItem('hk-theme')==='dark'){document.documentElement.classList.add('hk-dark');}}catch(e){}</script>

    <title>{{ Qs::getSystemName() ?: 'School Management System' }}</title>

    @include('partials.login.inc_top')
</head>

<body>
@include('partials.login.header')
@yield('content')
@include('partials.login.footer')

</body>

</html>
