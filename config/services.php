<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'otp' => [
        'driver' => env('OTP_DRIVER'),
        'fake_code' => env('OTP_FAKE_CODE', '123456'),
        'expires_minutes' => (int) env('OTP_EXPIRES_MINUTES', 5),
        'max_attempts' => (int) env('OTP_MAX_ATTEMPTS', 5),
    ],

    // AI đọc ảnh phiếu khám / đơn thuốc và lập kế hoạch chăm sóc (API tương thích OpenAI Chat Completions).
    // Khoá chỉ đặt trong .env, không bao giờ commit.
    'ai' => [
        'driver' => env('AI_DRIVER', 'fake'),
        'base_url' => rtrim((string) env('AI_BASE_URL', 'https://rexllm.xyz/v1'), '/'),
        'api_key' => env('AI_API_KEY'),
        'model' => env('AI_MODEL', 'gpt-5.6-sol'),
        'timeout' => (int) env('AI_TIMEOUT', 150),
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
