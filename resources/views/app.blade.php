<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#6c4cf1">
    <meta name="description" content="{{ __('app.tagline') }}">
    <meta name="app-base-url" content="{{ url('/') }}">
    <meta name="app-env" content="{{ app()->environment() }}">
    {{-- Ảnh xem trước khi dán link lên Zalo / Facebook / Messenger (public/og-image.png, 1200×630). Đổi ảnh thì tăng ?v= để các mạng xã hội tải lại. --}}
    @php($shareTitle = 'Sổ Sức Khỏe — chụp đơn thuốc, có ngay lịch uống thuốc và chế độ ăn')
    @php($shareDesc = 'Chụp đơn thuốc, phiếu xét nghiệm — AI đọc giúp, xếp giờ uống thuốc theo bữa ăn, gợi ý thực đơn và bài tập mỗi ngày. Miễn phí, không cần mã OTP.')
    @php($shareImage = asset('og-image.png') . '?v=1')
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="Sổ Sức Khỏe">
    <meta property="og:locale" content="vi_VN">
    <meta property="og:url" content="{{ url('/') }}">
    <meta property="og:title" content="{{ $shareTitle }}">
    <meta property="og:description" content="{{ $shareDesc }}">
    <meta property="og:image" content="{{ $shareImage }}">
    <meta property="og:image:type" content="image/png">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:alt" content="Sổ Sức Khỏe: chụp đơn thuốc, có ngay lịch uống thuốc và chế độ ăn uống">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $shareTitle }}">
    <meta name="twitter:description" content="{{ $shareDesc }}">
    <meta name="twitter:image" content="{{ $shareImage }}">
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
        @vite(['resources/css/tokens.css', 'resources/css/base.css', 'resources/css/components.css', 'resources/css/pages.css', 'resources/css/landing.css', 'resources/js/app.js'])
    @endif
</head>
<body>
    <div id="app"></div>
</body>
</html>
