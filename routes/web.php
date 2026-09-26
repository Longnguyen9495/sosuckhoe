<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'app')->name('app');

Route::get('/manifest.webmanifest', function () {
    $base = url('/');
    return response()->json([
        'name' => 'Sổ Sức Khỏe',
        'short_name' => 'Sổ Sức Khỏe',
        'lang' => 'vi',
        'start_url' => $base . '/',
        'scope' => $base . '/',
        'display' => 'standalone',
        'background_color' => '#f6f3ff',
        'theme_color' => '#6c4cf1',
        'icons' => [
            ['src' => asset('favicon.ico'), 'sizes' => '48x48', 'type' => 'image/x-icon'],
        ],
    ], 200, ['Content-Type' => 'application/manifest+json']);
})->name('manifest');
