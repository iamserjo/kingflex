<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'MarketKing') }} - Консультант</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=jetbrains-mono:400,500,600,700|outfit:300,400,500,600,700" rel="stylesheet" />

    @vite(['resources/css/app.css', 'resources/js/consultant.js'])

    <style>
        :root {
            --bg-primary: #0a0a0f;
            --bg-secondary: #12121a;
            --bg-card: #1a1a24;
            --border-color: #2a2a3a;
            --text-primary: #f0f0f5;
            --text-secondary: #8888a0;
            --accent-primary: #22c55e;
            --accent-secondary: #34d399;
            --accent-glow: rgba(34, 197, 94, 0.25);
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            padding: 0;
            font-family: 'Outfit', system-ui, sans-serif;
            background: var(--bg-primary);
            color: var(--text-primary);
            min-height: 100vh;
            overflow-x: hidden;
        }

        .bg-pattern {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 0;
            background:
                radial-gradient(ellipse at 20% 20%, rgba(34, 197, 94, 0.08) 0%, transparent 50%),
                radial-gradient(ellipse at 80% 80%, rgba(16, 185, 129, 0.06) 0%, transparent 50%),
                radial-gradient(ellipse at 50% 50%, rgba(59, 130, 246, 0.04) 0%, transparent 70%);
        }

        .container {
            position: relative;
            z-index: 1;
            max-width: 980px;
            margin: 0 auto;
            padding: 2rem;
            min-height: 100vh;
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
            font-size: 1.5rem;
            font-weight: 700;
            background: linear-gradient(135deg, var(--accent-primary) 0%, var(--accent-secondary) 60%, #a7f3d0 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            letter-spacing: -0.02em;
            margin: 0;
        }

        .subtitle {
            color: var(--text-secondary);
            font-size: 0.95rem;
            margin: 0;
            font-weight: 300;
        }

        .chat-shell {
            flex: 1;
            display: flex;
            flex-direction: column;
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 1.25rem;
            overflow: hidden;
            box-shadow: 0 4px 24px rgba(0, 0, 0, 0.3);
        }

        .messages {
            flex: 1;
            padding: 1.25rem;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .message {
            max-width: 78%;
            padding: 0.85rem 1rem;
            border-radius: 1rem;
            border: 1px solid var(--border-color);
            background: var(--bg-card);
            line-height: 1.55;
            white-space: pre-wrap;
            word-break: break-word;
        }

        .message-user {
            align-self: flex-end;
            background: rgba(34, 197, 94, 0.14);
            border-color: rgba(34, 197, 94, 0.35);
        }

        .message-assistant {
            align-self: flex-start;
        }

        .message-meta {
            margin-top: 0.5rem;
            font-size: 0.8rem;
            color: var(--text-secondary);
            font-family: 'JetBrains Mono', monospace;
        }

        .composer {
            padding: 1rem;
            border-top: 1px solid var(--border-color);
            display: flex;
            gap: 0.75rem;
            align-items: flex-end;
            background: rgba(0, 0, 0, 0.15);
        }

        .input {
            flex: 1;
            resize: none;
            min-height: 44px;
            max-height: 160px;
            padding: 0.75rem 0.9rem;
            border-radius: 0.9rem;
            border: 1px solid var(--border-color);
            background: rgba(10, 10, 15, 0.35);
            color: var(--text-primary);
            outline: none;
            font-family: 'Outfit', system-ui, sans-serif;
            font-size: 1rem;
            line-height: 1.4;
        }

        .input:focus {
            border-color: var(--accent-primary);
            box-shadow: 0 0 0 1px var(--accent-primary), 0 8px 24px var(--accent-glow);
        }

        .btn {
            border: none;
            cursor: pointer;
            border-radius: 0.9rem;
            padding: 0.75rem 1.1rem;
            font-weight: 600;
            font-family: 'Outfit', system-ui, sans-serif;
            transition: transform 0.15s ease, opacity 0.15s ease;
            white-space: nowrap;
        }

        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .btn-primary {
            color: #0b1a12;
            background: linear-gradient(135deg, var(--accent-primary) 0%, #10b981 100%);
        }

        .btn-secondary {
            color: var(--text-primary);
            background: var(--bg-card);
            border: 1px solid var(--border-color);
        }

        .btn:hover { transform: translateY(-1px); }
        .btn:active { transform: translateY(0); }

        @media (max-width: 640px) {
            .container { padding: 1rem; }
            .header { flex-direction: column; align-items: flex-start; }
            .message { max-width: 92%; }
        }
    </style>
</head>
<body>
    <div class="bg-pattern"></div>

    <div class="container">
        <header class="header">
            <div>
                <h1 class="logo">MarketKing</h1>
                <p class="subtitle">Консультант (tool calling: <span style="color: var(--accent-secondary);">search_products</span>)</p>
            </div>
            <button type="button" id="chat-reset" class="btn btn-secondary">New chat</button>
        </header>

        <div class="chat-shell">
            <div id="chat-messages" class="messages">
                <div class="message message-assistant">
                    Привет! Я консультант. Расскажите, что вы ищете (тип техники, бюджет/бренд/модель, для каких задач) — я уточню детали и при необходимости найду ссылки.
                </div>
            </div>

            <form id="chat-form" class="composer">
                <textarea id="chat-input" class="input" placeholder="Напишите сообщение..." rows="1"></textarea>
                <button type="submit" id="chat-send" class="btn btn-primary">Send</button>
            </form>
        </div>
    </div>
</body>
</html>



