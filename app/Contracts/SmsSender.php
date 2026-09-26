<?php

namespace App\Contracts;

interface SmsSender
{
    /**
     * Gửi SMS đến số điện thoại.
     *
     * @return array{sent: bool, message_id: string|null}
     */
    public function send(string $phone, string $message): array;
}
