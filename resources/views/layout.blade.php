<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Facturation électronique') — 42bash</title>
    <style>
        :root { --fg:#1f2933; --muted:#6b7280; --line:#e5e7eb; --bg:#f8fafc; --ok:#047857; --warn:#b45309; --err:#b91c1c; --accent:#1d4ed8; }
        * { box-sizing: border-box; }
        body { margin:0; font:14px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; color:var(--fg); background:var(--bg); }
        header { background:#fff; border-bottom:1px solid var(--line); padding:12px 24px; display:flex; gap:24px; align-items:center; flex-wrap:wrap; }
        header strong { font-size:16px; }
        header nav a { margin-right:16px; color:var(--accent); text-decoration:none; }
        main { padding:24px; max-width:1300px; margin:0 auto; }
        h1 { font-size:20px; margin:0 0 16px; } h2 { font-size:16px; margin:28px 0 10px; }
        .env { padding:2px 10px; border-radius:12px; font-weight:600; font-size:12px; }
        .env-sandbox { background:#fef3c7; color:var(--warn); } .env-production { background:#fee2e2; color:var(--err); }
        .cards { display:grid; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); gap:12px; }
        .card { background:#fff; border:1px solid var(--line); border-radius:8px; padding:12px 14px; }
        .card .n { font-size:22px; font-weight:600; } .card .l { color:var(--muted); font-size:12px; }
        table { width:100%; border-collapse:collapse; background:#fff; border:1px solid var(--line); border-radius:8px; overflow:hidden; }
        th, td { text-align:left; padding:7px 10px; border-bottom:1px solid var(--line); vertical-align:top; }
        th { background:#f1f5f9; font-weight:600; font-size:12px; color:var(--muted); }
        td.num { text-align:right; white-space:nowrap; }
        .badge { display:inline-block; padding:1px 8px; border-radius:10px; font-size:12px; background:#e5e7eb; white-space:nowrap; }
        .s-delivered,.s-imported,.s-submitted,.s-ready { background:#d1fae5; color:var(--ok); }
        .s-invalid,.s-rejected,.s-refused,.s-error,.s-needs_review { background:#fee2e2; color:var(--err); }
        .s-pending,.s-submitting,.s-received,.s-duplicate { background:#fef3c7; color:var(--warn); }
        .flash { padding:10px 14px; border-radius:6px; margin-bottom:16px; }
        .flash-ok { background:#d1fae5; color:var(--ok); } .flash-err { background:#fee2e2; color:var(--err); }
        .muted { color:var(--muted); } .err { color:var(--err); } .warn { color:var(--warn); }
        a { color:var(--accent); }
        button { background:var(--accent); color:#fff; border:0; border-radius:6px; padding:6px 14px; cursor:pointer; font:inherit; }
        button.secondary { background:#fff; color:var(--accent); border:1px solid var(--accent); }
        button.danger { background:var(--err); }
        form.inline { display:inline; }
        .box { background:#fff; border:1px solid var(--line); border-radius:8px; padding:14px 16px; margin-bottom:14px; }
        dl { display:grid; grid-template-columns:200px 1fr; gap:4px 12px; margin:0; } dt { color:var(--muted); }
        input[type=text], select, textarea { font:inherit; padding:5px 8px; border:1px solid var(--line); border-radius:6px; }
        pre { white-space:pre-wrap; word-break:break-word; font-size:12px; background:#f1f5f9; padding:8px; border-radius:6px; }
    </style>
</head>
<body>
<header>
    <strong>Facturation électronique</strong>
    <span class="env env-{{ $environment ?? config('einvoicing.superpdp.environment') }}">{{ strtoupper($environment ?? config('einvoicing.superpdp.environment')) }}</span>
    @if (! config('einvoicing.enabled'))<span class="badge">connecteur désactivé</span>@elseif (! config('einvoicing.send_enabled'))<span class="badge">émission désactivée</span>@endif
    <nav>
        <a href="{{ route('dashboard') }}">Tableau de bord</a>
        <a href="{{ route('journal') }}">Journal</a>
        <a href="{{ config('einvoicing.invoice_ninja.url') }}" rel="noopener" target="_blank">Invoice Ninja</a>
    </nav>
</header>
<main>
    @if (session('status'))<div class="flash flash-ok">{{ session('status') }}</div>@endif
    @if (session('error'))<div class="flash flash-err">{{ session('error') }}</div>@endif
    @if ($errors->any())<div class="flash flash-err">{{ implode(' ', $errors->all()) }}</div>@endif
    @yield('content')
</main>
</body>
</html>
