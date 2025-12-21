<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>{{ config('app.name', 'MarketKing') }} - Admin / Page Attributes</title>

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
            --accent-glow: rgba(99, 102, 241, 0.3);
            --danger: #ef4444;
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
            gap: 1.5rem;
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

        .card {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 1rem;
            padding: 1rem;
            box-shadow: 0 4px 24px rgba(0, 0, 0, 0.25);
        }

        .section-title {
            margin: 0 0 0.75rem 0;
            font-size: 1.05rem;
            font-weight: 600;
            color: var(--text-primary);
        }

        .hint {
            margin: 0.25rem 0 0 0;
            color: var(--text-secondary);
            font-size: 0.9rem;
            font-family: 'JetBrains Mono', monospace;
        }

        .table-wrap {
            overflow-x: auto;
        }

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

        .mono { font-family: 'JetBrains Mono', monospace; }

        a { color: var(--accent-secondary); text-decoration: none; }
        a:hover { text-decoration: underline; }

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
        }

        pre {
            margin: 0;
            padding: 0.75rem;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 0.75rem;
            max-height: 420px;
            overflow: auto;
            font-size: 0.82rem;
            line-height: 1.45;
            color: var(--text-primary);
        }

        .pre-compact {
            max-height: 240px;
        }

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

        .pagination {
            margin-top: 1rem;
            display: flex;
            justify-content: center;
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
                <h1 class="logo">Admin / Page Attributes</h1>
                <p class="subtitle">Pending candidates + extracted attributes</p>
            </div>
            <div class="pill">
                <span class="mono">domain</span>
                <span>{{ $domain ?? 'all' }}</span>
            </div>
        </header>

        <section class="card">
            <h2 class="section-title">Pending (next 5 candidates)</h2>
            <p class="hint">Same eligibility rules as <span class="mono">php artisan page:extract-attributes</span> (without --force).</p>

            @if ($pending->isEmpty())
                <div class="empty">No pending candidates.</div>
            @else
                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>ID</th>
                            <th>URL</th>
                            <th>Product type</th>
                            <th>Attributes</th>
                            <th>Summary specs</th>
                            <th>Abilities</th>
                            <th>Predicted search</th>
                            <th>Screenshot (crop=2300)</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($pending as $page)
                            @php
                                $typeName = $page->productType?->type ?? null;
                                $v = $page->screenshot_taken_at?->timestamp ?? $page->updated_at->timestamp;
                                $shotUrl = route('admin.pages.screenshot', $page) . '?crop=2300&v=' . $v;
                            @endphp
                            <tr>
                                <td class="mono">{{ $page->id }}</td>
                                <td>
                                    <a href="{{ $page->url }}" target="_blank" rel="noopener noreferrer">{{ $page->url }}</a>
                                </td>
                                <td>
                                    <div class="pill">
                                        <span class="mono">#{{ $page->product_type_id }}</span>
                                        <span>{{ $typeName ?? '—' }}</span>
                                    </div>
                                </td>
                                <td>
                                    <pre>{{ $page->json_attributes ? json_encode($page->json_attributes, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : 'null' }}</pre>
                                </td>
                                <td>
                                    <pre class="pre-compact">{{ $page->product_summary_specs ?? 'null' }}</pre>
                                </td>
                                <td>
                                    <pre class="pre-compact">{{ $page->product_abilities ?? 'null' }}</pre>
                                </td>
                                <td>
                                    <pre class="pre-compact">{{ $page->product_predicted_search_text ?? 'null' }}</pre>
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

        <section class="card">
            <h2 class="section-title">Processed (attributes extracted)</h2>
            <p class="hint">Filters: is_product=1, is_product_available=1, product_type_id!=null, json_attributes!=null. Paginate 50.</p>

            @if ($processed->total() === 0)
                <div class="empty">No processed pages.</div>
            @else
                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>ID</th>
                            <th>URL</th>
                            <th>Product type</th>
                            <th>Attributes</th>
                            <th>Summary specs</th>
                            <th>Abilities</th>
                            <th>Predicted search</th>
                            <th>Screenshot (crop=2300)</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($processed as $page)
                            @php
                                $typeName = $page->productType?->type ?? null;
                                $v = $page->screenshot_taken_at?->timestamp ?? $page->updated_at->timestamp;
                                $shotUrl = route('admin.pages.screenshot', $page) . '?crop=2300&v=' . $v;
                            @endphp
                            <tr>
                                <td class="mono">{{ $page->id }}</td>
                                <td>
                                    <a href="{{ $page->url }}" target="_blank" rel="noopener noreferrer">{{ $page->url }}</a>
                                </td>
                                <td>
                                    <div class="pill">
                                        <span class="mono">#{{ $page->product_type_id }}</span>
                                        <span>{{ $typeName ?? '—' }}</span>
                                    </div>
                                </td>
                                <td>
                                    <pre>{{ json_encode($page->json_attributes, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) }}</pre>
                                </td>
                                <td>
                                    <pre class="pre-compact">{{ $page->product_summary_specs ?? 'null' }}</pre>
                                </td>
                                <td>
                                    <pre class="pre-compact">{{ $page->product_abilities ?? 'null' }}</pre>
                                </td>
                                <td>
                                    <pre class="pre-compact">{{ $page->product_predicted_search_text ?? 'null' }}</pre>
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

                <div class="pagination">
                    {{ $processed->withQueryString()->links() }}
                </div>
            @endif
        </section>
    </div>
</body>
</html>


