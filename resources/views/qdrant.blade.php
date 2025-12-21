<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'MarketKing') }} - Qdrant Search</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=jetbrains-mono:400,500,600,700|outfit:300,400,500,600,700" rel="stylesheet" />

    @vite(['resources/css/app.css', 'resources/js/qdrant.js'])

    <style>
        :root {
            --bg-primary: #0a0a0f;
            --bg-secondary: #12121a;
            --bg-card: #1a1a24;
            --border-color: #2a2a3a;
            --text-primary: #f0f0f5;
            --text-secondary: #8888a0;
            --accent-primary: #6366f1;
            --accent-secondary: #818cf8;
            --accent-glow: rgba(99, 102, 241, 0.3);
            --danger: #ef4444;
            --success: #22c55e;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            padding: 0;
            font-family: 'Outfit', system-ui, sans-serif;
            background: var(--bg-primary);
            color: var(--text-primary);
            min-height: 100vh;
        }

        .bg-pattern {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 0;
            background:
                radial-gradient(ellipse at 20% 20%, rgba(99, 102, 241, 0.08) 0%, transparent 50%),
                radial-gradient(ellipse at 80% 80%, rgba(139, 92, 246, 0.06) 0%, transparent 50%),
                radial-gradient(ellipse at 50% 50%, rgba(59, 130, 246, 0.04) 0%, transparent 70%);
        }

        .container {
            position: relative;
            z-index: 1;
            max-width: 1100px;
            margin: 0 auto;
            padding: 2rem;
            display: flex;
            flex-direction: column;
            gap: 1.25rem;
        }

        .header {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 1rem;
        }

        .logo {
            font-family: 'JetBrains Mono', monospace;
            font-size: 1.6rem;
            font-weight: 700;
            margin: 0;
            background: linear-gradient(135deg, var(--accent-primary) 0%, var(--accent-secondary) 50%, #a78bfa 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .subtitle {
            color: var(--text-secondary);
            font-size: 0.95rem;
            margin: 0;
            font-weight: 300;
        }

        .card {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 1rem;
            padding: 1rem;
            box-shadow: 0 4px 24px rgba(0, 0, 0, 0.25);
        }

        .row {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
        }

        .input, .select, .btn {
            border-radius: 0.75rem;
            border: 1px solid var(--border-color);
            background: var(--bg-card);
            color: var(--text-primary);
            font-family: 'Outfit', system-ui, sans-serif;
            padding: 0.9rem 1rem;
            outline: none;
        }

        .input { flex: 1; min-width: 280px; }
        .select { min-width: 160px; }

        .btn {
            cursor: pointer;
            font-weight: 600;
            background: linear-gradient(135deg, var(--accent-primary) 0%, #7c3aed 100%);
            border: none;
        }

        .btn.secondary {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
        }

        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .pill {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.25rem 0.6rem;
            border-radius: 999px;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            color: var(--text-secondary);
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.8rem;
        }

        .error {
            display: none;
            padding: 0.75rem 1rem;
            border-radius: 0.75rem;
            border: 1px solid rgba(239, 68, 68, 0.35);
            background: rgba(239, 68, 68, 0.1);
            color: #fca5a5;
        }

        .error.visible { display: block; }

        .section-title {
            margin: 0 0 0.75rem 0;
            font-size: 1.05rem;
            font-weight: 600;
        }

        .mono {
            font-family: 'JetBrains Mono', monospace;
        }

        .debug {
            width: 100%;
            min-height: 160px;
            white-space: pre;
            overflow: auto;
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.85rem;
            line-height: 1.45;
        }

        .filters-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 0.75rem;
        }

        @media (min-width: 900px) {
            .filters-grid {
                grid-template-columns: 1fr 1fr;
            }
        }

        .filter-item {
            display: grid;
            grid-template-columns: 1fr 160px 1fr;
            gap: 0.5rem;
            align-items: center;
        }

        .filter-label {
            color: var(--text-secondary);
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.85rem;
        }

        .results {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .result-card {
            border: 1px solid var(--border-color);
            background: var(--bg-card);
            border-radius: 1rem;
            padding: 1rem;
        }

        .result-top {
            display: flex;
            justify-content: space-between;
            gap: 0.75rem;
            align-items: baseline;
        }

        .result-title {
            margin: 0;
            font-size: 1rem;
            font-weight: 600;
        }

        .result-title a { color: var(--text-primary); text-decoration: none; }
        .result-title a:hover { color: var(--accent-secondary); }

        .score {
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.85rem;
            color: var(--accent-secondary);
        }

        .payload-toggle {
            margin-top: 0.5rem;
            font-size: 0.85rem;
            color: var(--text-secondary);
            cursor: pointer;
            display: inline-block;
        }

        .payload {
            display: none;
            margin-top: 0.5rem;
            padding: 0.75rem;
            border-radius: 0.75rem;
            border: 1px solid var(--border-color);
            background: var(--bg-secondary);
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.8rem;
            white-space: pre-wrap;
        }

        .payload.visible { display: block; }
    </style>
</head>
<body>
<div class="bg-pattern"></div>

<div class="container">
    <header class="header">
        <div>
            <h1 class="logo">Qdrant Search</h1>
            <p class="subtitle">Type-aware semantic search with filters from TypeStructure</p>
        </div>
        <div class="pill" id="qdrant-stats">Loading…</div>
    </header>

    <div id="error" class="error"></div>

    <div class="card">
        <div class="row">
            <input id="query" class="input" placeholder="Например: Instax Mini 12, iPhone 15 Pro 256GB, Apple Watch 9..." autocomplete="off" />
            <button id="btn-plan" class="btn" type="button">Plan</button>
            <button id="btn-search" class="btn secondary" type="button" disabled>Search</button>
        </div>
        <div class="row" style="margin-top: 0.75rem;">
            <div class="pill">Type: <span id="type-label" class="mono">—</span></div>
            <div class="pill">QueryText: <input id="query-text" class="input mono" style="padding: 0.25rem 0.5rem; min-width: 320px;" /></div>
            <div class="pill">Limit: <input id="limit" class="input mono" style="padding: 0.25rem 0.5rem; min-width: 90px; max-width: 120px;" value="20" /></div>
        </div>
    </div>

    <div class="card">
        <h2 class="section-title">Generated Qdrant JSON (debug)</h2>
        <textarea id="debug" class="debug input mono" readonly></textarea>
    </div>

    <div class="card">
        <h2 class="section-title">Results</h2>
        <div id="results" class="results"></div>
    </div>
</div>
</body>
</html>


