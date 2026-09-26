<?php

namespace App\Services\Zalo;

use App\Contracts\ZaloZnsSender;

final class FakeZaloZnsSender implements ZaloZnsSender
{
    public function send(string $phone, string $templateCode, array $templateData): array
    {
        return [
            'sent' => true,
            'message_id' => 'zns-' . uniqid(),
        ];
    }
}
