{{--
    Workstream DG — branded 429 (extends the Loop 9 error-page family).
    Shown when a rate limit trips (login, password reset). Standalone
    and dependency-free like the rest of the family.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Too many attempts | Hackstra</title>
    <style>
        * { box-sizing: border-box; }
        :root { --bg: #f4f7fb; --card: #ffffff; --border: #e4eaf1; --ink: #10263b; --text: #172536; --muted: #44546a; --shadow: rgba(16, 38, 59, .08); }
        html.hk-dark { --bg: #0a1420; --card: #10263b; --border: #1e3a52; --ink: #e8eef5; --text: #dbe5ef; --muted: #a9bcd0; --shadow: rgba(0, 0, 0, .35); }
        body { margin: 0; font-family: "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; background: var(--bg); color: var(--text); }
        .wrap { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px; }
        .card { background: var(--card); border: 1px solid var(--border); border-radius: 12px; max-width: 520px; width: 100%; padding: 40px 36px; text-align: center; box-shadow: 0 8px 30px var(--shadow); }
        .brand { font-size: 13px; font-weight: 700; letter-spacing: .14em; text-transform: uppercase; color: #2867dc; margin: 0 0 18px; }
        .code { font-size: 56px; font-weight: 700; line-height: 1; color: var(--ink); margin: 0 0 10px; }
        h1 { font-size: 20px; margin: 0 0 10px; color: var(--ink); }
        p { font-size: 14px; line-height: 1.6; color: var(--muted); margin: 0 0 24px; }
        .btn { display: inline-block; background: #2867dc; color: #fff; text-decoration: none; font-size: 14px; font-weight: 600; padding: 11px 22px; border-radius: 8px; margin: 0 4px 8px; }
        .btn-light { background: transparent; color: #2867dc; border: 1px solid #2867dc; }
    </style>
    <script>try{if(localStorage.getItem('hk-theme')!=='light'){document.documentElement.classList.add('hk-dark');}}catch(e){document.documentElement.classList.add('hk-dark');}</script>
</head>
<body>
@php
    // Round 5 Slice 1 (P17): honour Retry-After when the server sent
    // one — a concrete wait beats a generic one whenever the server
    // actually knows. Arrives as seconds or an HTTP-date; humanised.
    $hkRetryAfter = null;
    try {
        $hkHeaders = isset($exception) && method_exists($exception, 'getHeaders') ? $exception->getHeaders() : [];
        $hkRaw = $hkHeaders['Retry-After'] ?? $hkHeaders['retry-after'] ?? null;
        if ($hkRaw !== null && $hkRaw !== '') {
            $hkSeconds = is_numeric($hkRaw) ? (int) $hkRaw : max(0, (int) strtotime((string) $hkRaw) - time());
            $hkRetryAfter = $hkSeconds < 60
                ? 'about ' . max(1, $hkSeconds) . ' seconds'
                : 'about ' . (int) ceil($hkSeconds / 60) . ' minutes';
        }
    } catch (\Throwable $e) { $hkRetryAfter = null; }
@endphp
    <div class="wrap">
        <div class="card">
            <p class="brand">Hackstra</p>
            <p class="code">429</p>
            <h1>Too many attempts</h1>
            <p>You have made too many requests in a short time. @if($hkRetryAfter)Please try again in {{ $hkRetryAfter }}.@else Please wait a minute, then try again.@endif This limit protects every account from automated guessing.</p>
            <a class="btn" href="{{ url('/') }}">Back to dashboard</a>
            <a class="btn btn-light" href="javascript:history.back()">Go back</a>
        </div>
    </div>
</body>
</html>
