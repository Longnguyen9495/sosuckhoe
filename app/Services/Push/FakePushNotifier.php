<?php

namespace App\Services\Push;

use App\Contracts\PushNotifier;
use Illuminate\Support\Facades\Log;

final class FakePushNotifier implements PushNotifier
{
    public function send(array $recipients, string $title, string $body, array $data = []): array
    {
        Log::info('[FAKE_PUSH] Đã mô phỏng gửi thông báo', [
            'recipient_count' => count($recipients),
            'data_keys' => array_keys($data),
        ]);

        return ['sent' => count($recipients), 'failed' => 0];
    }
}
