<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'MarketKing') }} - Search</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=jetbrains-mono:400,500,600,700|outfit:300,400,500,600,700" rel="stylesheet" />

    @vite(['resources/css/app.css', 'resources/js/search.js'])

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
            --success: #22c55e;
            --warning: #f59e0b;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 0;
            font-family: 'Outfit', system-ui, sans-serif;
            background: var(--bg-primary);
            color: var(--text-primary);
            min-height: 100vh;
            overflow-x: hidden;
        }

        /* Animated background */
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
            animation: bgPulse 15s ease-in-out infinite;
        }

        @keyframes bgPulse {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.8; transform: scale(1.02); }
        }

        .container {
            position: relative;
            z-index: 1;
            max-width: 900px;
            margin: 0 auto;
            padding: 2rem;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* Header */
        .header {
            text-align: center;
            padding: 4rem 0 3rem;
        }

        .logo {
            font-family: 'JetBrains Mono', monospace;
            font-size: 2.5rem;
            font-weight: 700;
            background: linear-gradient(135deg, var(--accent-primary) 0%, var(--accent-secondary) 50%, #a78bfa 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            letter-spacing: -0.02em;
            margin-bottom: 0.5rem;
        }

        .tagline {
            color: var(--text-secondary);
            font-size: 1.1rem;
            font-weight: 300;
        }

        /* Search box */
        .search-container {
            margin-bottom: 3rem;
        }

        .search-box {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 1.25rem;
            padding: 0.5rem;
            display: flex;
            gap: 0.5rem;
            transition: all 0.3s ease;
            box-shadow: 0 4px 24px rgba(0, 0, 0, 0.3);
        }

        .search-box:focus-within {
            border-color: var(--accent-primary);
            box-shadow: 0 4px 32px var(--accent-glow), 0 0 0 1px var(--accent-primary);
        }

        .search-input {
            flex: 1;
            background: transparent;
            border: none;
            padding: 1.25rem 1.5rem;
            font-size: 1.25rem;
            color: var(--text-primary);
            font-family: 'Outfit', sans-serif;
            outline: none;
        }

        .search-input::placeholder {
            color: var(--text-secondary);
            opacity: 0.7;
        }

        .search-btn {
            background: linear-gradient(135deg, var(--accent-primary) 0%, #7c3aed 100%);
            border: none;
            border-radius: 0.875rem;
            padding: 1.25rem 2.5rem;
            font-size: 1.1rem;
            font-weight: 600;
            color: white;
            cursor: pointer;
            transition: all 0.3s ease;
            font-family: 'Outfit', sans-serif;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .search-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px var(--accent-glow);
        }

        .search-btn:active {
            transform: translateY(0);
        }

        .search-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .search-btn svg {
            width: 1.25rem;
            height: 1.25rem;
        }

        /* Loading spinner */
        .spinner {
            width: 1.25rem;
            height: 1.25rem;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-top-color: white;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Parsed tags display */
        .parsed-tags {
            margin-bottom: 2rem;
            padding: 1.25rem;
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 1rem;
            display: none;
        }

        .parsed-tags.visible {
            display: block;
            animation: fadeIn 0.3s ease;
        }

        .parsed-tags-title {
            font-size: 0.85rem;
            color: var(--text-secondary);
            margin-bottom: 0.75rem;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .tags-list {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .tag-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 0.875rem;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 2rem;
            font-size: 0.9rem;
            transition: all 0.2s ease;
        }

        .tag-badge:hover {
            border-color: var(--accent-primary);
            transform: translateY(-1px);
        }

        .tag-name {
            color: var(--text-primary);
        }

        .tag-weight {
            color: var(--accent-secondary);
            font-size: 0.75rem;
            font-weight: 600;
            font-family: 'JetBrains Mono', monospace;
            padding: 0.125rem 0.375rem;
            background: rgba(99, 102, 241, 0.15);
            border-radius: 0.25rem;
        }

        /* Results section */
        .results {
            flex: 1;
        }

        .results-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border-color);
        }

        .results-count {
            font-size: 1rem;
            color: var(--text-secondary);
        }

        .results-count strong {
            color: var(--text-primary);
        }

        /* Result card */
        .result-card {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 1rem;
            padding: 1.5rem;
            margin-bottom: 1rem;
            transition: all 0.3s ease;
        }

        .result-card:hover {
            border-color: var(--accent-primary);
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.2);
        }

        .result-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 0.75rem;
        }

        .result-title {
            font-size: 1.15rem;
            font-weight: 600;
            color: var(--text-primary);
            margin: 0 0 0.25rem 0;
            line-height: 1.4;
        }

        .result-title a {
            color: inherit;
            text-decoration: none;
            transition: color 0.2s ease;
        }

        .result-title a:hover {
            color: var(--accent-secondary);
        }

        .result-url {
            font-size: 0.85rem;
            color: var(--text-secondary);
            word-break: break-all;
            font-family: 'JetBrains Mono', monospace;
        }

        .result-score {
            background: linear-gradient(135deg, var(--accent-primary), #7c3aed);
            color: white;
            padding: 0.375rem 0.75rem;
            border-radius: 0.5rem;
            font-size: 0.85rem;
            font-weight: 600;
            font-family: 'JetBrains Mono', monospace;
            white-space: nowrap;
        }

        .result-summary {
            color: var(--text-secondary);
            font-size: 0.95rem;
            line-height: 1.6;
            margin-bottom: 1rem;
        }

        .result-type {
            display: inline-block;
            padding: 0.25rem 0.625rem;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 0.375rem;
            font-size: 0.75rem;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .matched-tags {
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid var(--border-color);
        }

        .matched-tags-title {
            font-size: 0.75rem;
            color: var(--text-secondary);
            margin-bottom: 0.5rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .matched-tag {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.25rem 0.5rem;
            background: rgba(34, 197, 94, 0.1);
            border: 1px solid rgba(34, 197, 94, 0.3);
            border-radius: 0.375rem;
            font-size: 0.8rem;
            color: var(--success);
            margin-right: 0.5rem;
            margin-bottom: 0.5rem;
        }

        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            color: var(--text-secondary);
        }

        .empty-state svg {
            width: 4rem;
            height: 4rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        .empty-state h3 {
            color: var(--text-primary);
            margin-bottom: 0.5rem;
        }

        /* Error state */
        .error-message {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.3);
            border-radius: 0.75rem;
            padding: 1rem 1.5rem;
            color: #f87171;
            margin-bottom: 1.5rem;
            display: none;
        }

        .error-message.visible {
            display: block;
            animation: fadeIn 0.3s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Responsive */
        @media (max-width: 640px) {
            .container {
                padding: 1rem;
            }

            .header {
                padding: 2rem 0 1.5rem;
            }

            .logo {
                font-size: 1.75rem;
            }

            .search-box {
                flex-direction: column;
            }

            .search-btn {
                justify-content: center;
            }

            .search-input {
                font-size: 1.1rem;
                padding: 1rem;
            }

            .result-header {
                flex-direction: column;
                gap: 0.75rem;
            }

            .result-score {
                align-self: flex-start;
            }
        }
    </style>
</head>
<body>
    <div class="bg-pattern"></div>

    <div class="container">
        <header class="header">
            <h1 class="logo">MarketKing</h1>
            <p class="tagline">AI-Powered Smart Search</p>
        </header>

        <div class="search-container">
            <form id="search-form" class="search-box">
                <input
                    type="text"
                    id="search-input"
                    class="search-input"
                    placeholder="What are you looking for?"
                    autocomplete="off"
                    autofocus
                >
                <button type="submit" id="search-btn" class="search-btn">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                    </svg>
                    <span>Search</span>
                </button>
            </form>
        </div>

        <div id="error-message" class="error-message"></div>

        <div id="parsed-tags" class="parsed-tags">
            <div class="parsed-tags-title">Parsed Search Tags</div>
            <div id="tags-list" class="tags-list"></div>
        </div>

        <div class="results" id="results">
            <div class="empty-state">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                </svg>
                <h3>Start Searching</h3>
                <p>Enter your query above to find relevant pages</p>
            </div>
        </div>
    </div>
</body>
</html>

