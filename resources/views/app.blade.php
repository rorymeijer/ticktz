<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="color-scheme" content="light">
        {{-- Self-hosted service desk: no third-party fonts, analytics or trackers. --}}
        <meta name="robots" content="noindex, nofollow">

        <title inertia>{{ config('app.name', 'Ticktz') }}</title>

        <link rel="icon" href="/favicon.svg" type="image/svg+xml">

        @routes
        @viteReactRefresh
        @vite(['resources/js/app.tsx', "resources/js/Pages/{$page['component']}.tsx"])
        @inertiaHead
    </head>
    <body class="h-full font-sans antialiased">
        @inertia
    </body>
</html>
