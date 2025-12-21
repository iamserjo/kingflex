<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>{{ config('app.name', 'MarketKing') }} - Admin / Product Type Queue</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=jetbrains-mono:400,500,600,700|outfit:300,400,500,600,700" rel="stylesheet" />

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
            max-width: 1400px;
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
            font-size: 1.4rem;
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

        .pill {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.25rem 0.6rem;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 999px;
            font-size: 0.8rem;
            color: var(--text-secondary);
            white-space: nowrap;
            font-family: 'JetBrains Mono', monospace;
        }

        .pill-success {
            background: rgba(34, 197, 94, 0.14);
            border-color: rgba(34, 197, 94, 0.35);
            color: var(--success);
        }

        .pill-muted {
            background: rgba(136, 136, 160, 0.12);
            border-color: rgba(136, 136, 160, 0.28);
            color: var(--text-secondary);
        }

        .card {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 1rem;
            padding: 1rem;
            box-shadow: 0 4px 24px rgba(0, 0, 0, 0.25);
        }

        /* Pending table rows highlight */
        .pending-row td {
            background: rgba(34, 197, 94, 0.08);
        }

        .pending-row td:first-child {
            border-top-left-radius: 0.5rem;
            border-bottom-left-radius: 0.5rem;
        }

        .pending-row td:last-child {
            border-top-right-radius: 0.5rem;
            border-bottom-right-radius: 0.5rem;
        }

        .section-title {
            margin: 0 0 0.75rem 0;
            font-size: 1.05rem;
            font-weight: 600;
        }

        .hint {
            margin: 0.25rem 0 0.75rem 0;
            color: var(--text-secondary);
            font-size: 0.9rem;
            font-family: 'JetBrains Mono', monospace;
        }

        .table-wrap { overflow-x: auto; }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1100px;
        }

        th, td {
            border-bottom: 1px solid var(--border-color);
            text-align: left;
            vertical-align: top;
            padding: 0.75rem;
            font-size: 0.95rem;
        }

        th {
            position: sticky;
            top: 0;
            background: rgba(18, 18, 26, 0.92);
            backdrop-filter: blur(6px);
            z-index: 2;
            font-family: 'JetBrains Mono', monospace;
            color: var(--text-secondary);
            font-weight: 600;
            font-size: 0.85rem;
            letter-spacing: 0.02em;
        }

        a { color: var(--accent-secondary); text-decoration: none; }
        a:hover { text-decoration: underline; }

        .shot {
            width: 360px;
            border-radius: 0.75rem;
            border: 1px solid var(--border-color);
            overflow: hidden;
            background: rgba(0,0,0,0.25);
        }

        .shot img {
            width: 100%;
            height: auto;
            display: block;
        }

        .shot-meta {
            padding: 0.5rem 0.75rem;
            border-top: 1px solid var(--border-color);
            color: var(--text-secondary);
            font-size: 0.8rem;
            font-family: 'JetBrains Mono', monospace;
        }

        .empty {
            color: var(--text-secondary);
            padding: 0.75rem;
            border: 1px dashed var(--border-color);
            border-radius: 0.75rem;
        }

        @media (max-width: 900px) {
            .container { padding: 1rem; }
            .shot { width: 300px; }
        }
    </style>
</head>
<body>
    <div class="bg-pattern"></div>

    <div class="container">
        <header class="header">
            <div>
                <h1 class="logo">Admin / Product Type Queue</h1>
                <p class="subtitle">Upcoming candidates for <span class="pill">page:product-type-detect</span></p>
            </div>
            <div class="pill">
                <span>domain</span>
                <span>{{ $domain ?? 'all' }}</span>
            </div>
        </header>

        <section id="pending-product-type" class="card">
            <h2 class="section-title">Pending (next 5 candidates)</h2>
            <p class="hint">Selection rules are shared with the command (default behavior without --force).</p>

            @if ($pending->isEmpty())
                <div class="empty">No pending candidates.</div>
            @else
                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>ID</th>
                            <th>URL</th>
                            <th>product_type_detected_at</th>
                            <th>is_product</th>
                            <th>availability</th>
                            <th>product_type</th>
                            <th>Screenshot (crop=2300)</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($pending as $page)
                            @php
                                $v = $page->screenshot_taken_at?->timestamp ?? $page->updated_at->timestamp;
                                $shotUrl = route('admin.pages.screenshot', $page) . '?crop=2300&v=' . $v;
                                $typeName = $page->productType?->type ?? null;
                            @endphp
                            <tr class="pending-row">
                                <td class="pill">{{ $page->id }}</td>
                                <td>
                                    <a href="{{ $page->url }}" target="_blank" rel="noopener noreferrer">{{ $page->url }}</a>
                                </td>
                                <td class="pill">{{ $page->product_type_detected_at?->format('Y-m-d H:i:s') ?? 'null' }}</td>
                                <td class="pill">{{ is_null($page->is_product) ? 'null' : ($page->is_product ? 'true' : 'false') }}</td>
                                <td>
                                    @if ($page->is_product_available === true)
                                        <span class="pill pill-success">available</span>
                                    @elseif ($page->is_product_available === false)
                                        <span class="pill pill-muted">not available</span>
                                    @else
                                        <span class="pill pill-muted">unknown</span>
                                    @endif
                                </td>
                                <td>
                                    <span class="pill">#{{ $page->product_type_id ?? 'null' }}</span>
                                    @if ($typeName)
                                        <span class="pill">{{ $typeName }}</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($page->screenshot_path)
                                        <div class="shot">
                                            <img src="{{ $shotUrl }}" alt="Screenshot for page {{ $page->id }}" loading="lazy">
                                            <div class="shot-meta">crop=2300</div>
                                        </div>
                                    @else
                                        <div class="empty">No screenshot_path</div>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>
</body>
</html>


