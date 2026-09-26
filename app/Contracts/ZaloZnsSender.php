<?php

namespace App\Contracts;

interface ZaloZnsSender
{
    /**
     * Gửi tin nhắn ZNS qua Zalo OA.
     *
     * @param array<string, mixed> $templateData Dữ liệu điền vào mẫu ZNS
     * @return array{sent: bool, message_id: string|null}
     */
    public function send(string $phone, string $templateCode, array $templateData): array;
}
