<!doctype html>
<html lang="en">
<head>
    @php
        $appPath = parse_url(config('app.url', ''), PHP_URL_PATH) ?: '';
        $appPath = rtrim($appPath, '/');
        if ($appPath === '/') {
            $appPath = '';
        }
    @endphp
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'OQLook') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.jsx'])
    <script>window.OQLOOK_BASE_PATH = @json($appPath);</script>
    @inertiaHead
</head>
<body class="min-h-screen bg-slate-50 text-slate-900 antialiased">
    @inertia
</body>
</html>
