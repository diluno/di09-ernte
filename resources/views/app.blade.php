<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="paper" data-density="comfortable">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title inertia>{{ config('app.name', 'Laravel') }}</title>

        <!-- Favicon -->
        <link rel="icon" href="/favicon.ico" sizes="32x32">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        <!-- Fonts -->
        <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
        <link href="https://cdn.jsdelivr.net/npm/@fontsource/iosevka-aile@5/400.css" rel="stylesheet">
        <link href="https://cdn.jsdelivr.net/npm/@fontsource/iosevka-aile@5/500.css" rel="stylesheet">
        <link href="https://cdn.jsdelivr.net/npm/@fontsource/iosevka-aile@5/600.css" rel="stylesheet">
        <link href="https://cdn.jsdelivr.net/npm/@fontsource/iosevka-aile@5/700.css" rel="stylesheet">

        <!-- Scripts -->
        @routes
        @vite(['resources/js/app.js', "resources/js/Pages/{$page['component']}.vue"])
        @inertiaHead
    </head>
    <body>
        @inertia
    </body>
</html>
