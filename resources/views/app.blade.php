<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, nofollow">
        <meta name="color-scheme" content="light">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">

        <title data-inertia>{{ config('app.name', 'TechIndex') }}</title>

        @routes(nonce: \Illuminate\Support\Facades\Vite::cspNonce())
        @vite(['resources/js/app.ts', "resources/js/pages/{$page['component']}.vue"])
        @inertiaHead
    </head>
    <body class="h-full bg-slate-50 font-sans text-slate-800 antialiased">
        @inertia
    </body>
</html>
