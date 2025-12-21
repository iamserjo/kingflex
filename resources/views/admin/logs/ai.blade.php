<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>{{ config('app.name', 'MarketKing') }} - Admin / AI Logs</title>

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
            max-width: 1600px;
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

        .card {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 1rem;
            padding: 1rem;
            box-shadow: 0 4px 24px rgba(0, 0, 0, 0.25);
        }

        .filters {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            align-items: flex-end;
        }

        .field {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
            min-width: 200px;
        }

        label {
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.8rem;
            color: var(--text-secondary);
        }

        input, select {
            border-radius: 0.75rem;
            border: 1px solid var(--border-color);
            background: var(--bg-card);
            color: var(--text-primary);
            padding: 0.55rem 0.75rem;
            outline: none;
        }

        .btn {
            border-radius: 0.75rem;
            border: 1px solid rgba(99, 102, 241, 0.45);
            background: rgba(99, 102, 241, 0.18);
            color: var(--text-primary);
            padding: 0.55rem 0.9rem;
            cursor: pointer;
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.85rem;
        }

        .btn:hover {
            border-color: rgba(129, 140, 248, 0.65);
            background: rgba(129, 140, 248, 0.20);
        }

        .table-wrap { overflow-x: auto; }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1300px;
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

        .pill {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            padding: 0.25rem 0.6rem;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 999px;
            font-size: 0.8rem;
            color: var(--text-secondary);
            white-space: nowrap;
            font-family: 'JetBrains Mono', monospace;
        }

        .pill-ok {
            background: rgba(34, 197, 94, 0.12);
            border-color: rgba(34, 197, 94, 0.28);
            color: var(--success);
        }

        .pill-err {
            background: rgba(239, 68, 68, 0.12);
            border-color: rgba(239, 68, 68, 0.28);
            color: var(--danger);
        }

        pre {
            margin: 0;
            padding: 0.75rem;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 0.75rem;
            max-height: 380px;
            overflow: auto;
            font-size: 0.82rem;
            line-height: 1.45;
            color: var(--text-primary);
        }

        .pagination {
            margin-top: 1rem;
            display: flex;
            justify-content: center;
        }

        .empty {
            color: var(--text-secondary);
            padding: 0.75rem;
            border: 1px dashed var(--border-color);
            border-radius: 0.75rem;
        }
    </style>
</head>
<body>
    <div class="bg-pattern"></div>

    <div class="container">
        <header class="header">
            <div>
                <h1 class="logo">Admin / AI Logs</h1>
                <p class="subtitle">OpenRouter + OpenAI-compatible + Ollama requests saved to DB</p>
            </div>
            <div class="pill">
                <span class="mono">per_page</span>
                <span>{{ $perPage }}</span>
            </div>
        </header>

        <section class="card">
            <form method="get" action="{{ route('admin.logs.ai') }}" class="filters">
                <div class="field">
                    <label for="provider">provider</label>
                    <select id="provider" name="provider">
                        <option value="">all</option>
                        <option value="openrouter" @selected($provider === 'openrouter')>openrouter</option>
                        <option value="openai_compatible" @selected($provider === 'openai_compatible')>openai_compatible</option>
                        <option value="ollama" @selected($provider === 'ollama')>ollama</option>
                    </select>
                </div>

                <div class="field">
                    <label for="status">status</label>
                    <select id="status" name="status">
                        <option value="">all</option>
                        <option value="ok" @selected($status === 'ok')>ok</option>
                        <option value="error" @selected($status === 'error')>error</option>
                        <option value="200" @selected($status === '200')>200</option>
                        <option value="400" @selected($status === '400')>400</option>
                        <option value="401" @selected($status === '401')>401</option>
                        <option value="429" @selected($status === '429')>429</option>
                        <option value="500" @selected($status === '500')>500</option>
                    </select>
                </div>

                <div class="field">
                    <label for="model">model</label>
                    <input id="model" name="model" value="{{ $model ?? '' }}" placeholder="e.g. gpt-4o-mini" />
                </div>

                <div class="field">
                    <label for="per_page">per_page</label>
                    <select id="per_page" name="per_page">
                        @foreach ([10, 25, 50, 100, 200] as $n)
                            <option value="{{ $n }}" @selected($perPage === $n)>{{ $n }}</option>
                        @endforeach
                    </select>
                </div>

                <button class="btn" type="submit">Apply</button>
            </form>
        </section>

        <section class="card">
            @if ($logs->total() === 0)
                <div class="empty">No AI logs yet.</div>
            @else
                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>ID</th>
                            <th>When</th>
                            <th>Provider</th>
                            <th>Model</th>
                            <th>Status</th>
                            <th>Duration</th>
                            <th>Route</th>
                            <th>Request</th>
                            <th>Response</th>
                            <th>Error</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($logs as $log)
                            @php
                                $ok = empty($log->error);
                                $statusCode = $log->status_code;
                                $route = trim((string) ($log->path ?? ''));
                                $route = $route !== '' ? $route : '—';
                            @endphp
                            <tr>
                                <td class="mono">{{ $log->id }}</td>
                                <td class="mono">{{ $log->created_at?->format('Y-m-d H:i:s') }}</td>
                                <td><span class="pill">{{ $log->provider }}</span></td>
                                <td class="mono">{{ $log->model ?? '—' }}</td>
                                <td>
                                    @if ($ok)
                                        <span class="pill pill-ok">{{ $statusCode ?? 'ok' }}</span>
                                    @else
                                        <span class="pill pill-err">{{ $statusCode ?? 'error' }}</span>
                                    @endif
                                </td>
                                <td class="mono">{{ $log->duration_ms !== null ? ($log->duration_ms . 'ms') : '—' }}</td>
                                <td class="mono">{{ $route }}</td>
                                <td>
                                    <pre>{{ $log->request_payload ? json_encode($log->request_payload, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : 'null' }}</pre>
                                </td>
                                <td>
                                    @if ($log->response_payload)
                                        <pre>{{ json_encode($log->response_payload, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) }}</pre>
                                    @elseif ($log->response_body)
                                        <pre>{{ $log->response_body }}</pre>
                                    @else
                                        <pre>null</pre>
                                    @endif
                                </td>
                                <td>
                                    <pre>{{ $log->error ? json_encode($log->error, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : 'null' }}</pre>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="pagination">
                    {{ $logs->withQueryString()->links() }}
                </div>
            @endif
        </section>
    </div>
</body>
</html>


