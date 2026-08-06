<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Laravel') }}</title>

        <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
        <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
        <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,500;12..96,600;12..96,700;12..96,800&family=Hanken+Grotesk:wght@400;500;600;700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])

        <!-- Styles -->
        @livewireStyles

        <style>
            :root {
                --paper: #f5f4ef;
                --panel: #fdfcf9;
                --ink: #1c2036;
                --ink-soft: #565a72;
                --gold: #c99a2e;
                --gold-bright: #eec13f;
                --indigo: #4a52a0;
                --indigo-soft: #6b74c4;
                --line: rgba(28, 32, 54, 0.12);
                --font-display: 'Bricolage Grotesque', ui-sans-serif, sans-serif;
                --font-body: 'Hanken Grotesk', system-ui, sans-serif;
                --font-mono: 'DM Mono', ui-monospace, monospace;
            }
            html { background: var(--paper); }
            body { font-family: var(--font-body); background: var(--paper); color: var(--ink); }
            .font-display { font-family: var(--font-display); }
            .font-mono { font-family: var(--font-mono); }
        </style>
    </head>
    <body>
        <div class="font-body text-[var(--ink)] antialiased">
            {{ $slot }}
        </div>

        @livewireScripts
    </body>
</html>
