<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#6c4cf1">
    <meta name="description" content="{{ __('app.tagline') }}">
    <meta name="app-base-url" content="{{ url('/') }}">
    <meta name="app-env" content="{{ app()->environment() }}">
    <link rel="manifest" href="{{ route('manifest') }}">
    <link rel="icon" href="{{ asset('favicon.ico') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <title>{{ __('app.name') }}</title>
    @if (app()->environment('testing'))
        <link rel="stylesheet" href="/build/assets/app.css">
        <script type="module" src="/build/assets/app.js"></script>
    @else
        @vite(['resources/css/tokens.css', 'resources/css/base.css', 'resources/css/components.css', 'resources/css/pages.css', 'resources/js/app.js'])
    @endif
</head>
<body>
    <div id="app"></div>
</body>
</html>
