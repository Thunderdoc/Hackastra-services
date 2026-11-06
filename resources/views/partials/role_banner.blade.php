@php $hkBnHour = (int) now()->format('H'); $hkBnGreeting = $hkBnHour < 12 ? 'Good morning' : ($hkBnHour < 17 ? 'Good afternoon' : 'Good evening'); @endphp
<div class="hk-banner-hero hk-bn-{{ $hkRole }}" role="img" aria-label="{{ $hkLabel }} dashboard banner">
    <div class="hk-bn-overlay">
        <div class="hk-bn-greet">{{ $hkBnGreeting }}, {{ Auth::user()->name }} <span aria-hidden="true">&#128075;</span></div>
        <div class="hk-bn-sub">{{ $hkLabel }} &middot; {{ now()->format('l, d F Y') }}</div>
    </div>
</div>
