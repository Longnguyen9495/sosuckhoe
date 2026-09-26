<?php

namespace App\Services\Sms;

use App\Contracts\SmsSender;

final class FakeSmsSender implements SmsSender
{
    public function send(string $phone, string $message): array
    {
        return [
            'sent' => true,
            'message_id' => 'sms-' . uniqid(),
        ];
    }
}
