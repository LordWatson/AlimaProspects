<!DOCTYPE html>
<html
    lang="{{ str_replace('_', '-', app()->getLocale()) }}"
    dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}"
    @class(['dark' => ($appearance ?? 'light') == 'dark'])
>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- Inline script to detect system dark mode preference and apply it immediately --}}
        <script>
            (function() {
                const appearance = '{{ $appearance ?? "light" }}';

                if (appearance === 'system') {
                    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                    if (prefersDark) {
                        document.documentElement.classList.add('dark');
                    }
                }
            })();
        </script>

        {{-- Inline style sets the page background to brand Cloud Cream / Midnight Ink
             before the stylesheet loads, so there is no flash of the wrong colour. --}}
        <style>
            html {
                background-color: #f5f2e8;
            }

            html.dark {
                background-color: #181723;
            }
        </style>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        {{-- Brand fonts via Bunny (GDPR-friendly, drop-in for Google Fonts) —
             Poppins (Latin), DM Mono (labels), Tajawal (Arabic). brand-tokens.md §3. --}}
        <link rel="preconnect" href="https://fonts.bunny.net" crossorigin>
        <link
            href="https://fonts.bunny.net/css?family=poppins:300,400,500,600,700|dm-mono:400,500|tajawal:400,500,700&display=swap"
            rel="stylesheet"
        >

        @vite(['resources/css/app.css', 'resources/js/app.ts', "resources/js/pages/{$page['component']}.vue"])
        <x-inertia::head>
            <title>{{ config('app.name', 'Laravel') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
