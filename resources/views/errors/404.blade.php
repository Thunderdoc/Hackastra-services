{{--
    Workstream DG — branded 404 (extends the Loop 9 error-page family).
    Deliberately standalone: no layout, no database, no external assets,
    so it renders even when the failure is in any of those. Theme follows
    the browser's stored hk-theme choice exactly like the login page
    (dark unless the user last chose light). Colours are the Hackstra
    brand pair; the button fill is the contrast-verified action blue
    from docs/UX_STANDARDS.md Fix 3 (#2867dc, white text 5.17:1).
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Page not found | Hackstra</title>
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
    <div class="wrap">
        <div class="card">
            <p class="brand">Hackstra</p>
            <p class="code">404</p>
            <h1>This page could not be found</h1>
            <p>The page you are looking for may have been moved, renamed, or is no longer available. Check the address, or return to the dashboard to continue.</p>
            <a class="btn" href="{{ url('/') }}">Back to dashboard</a>
            <a class="btn btn-light" href="javascript:history.back()">Go back</a>
        </div>
    </div>
</body>
</html>
