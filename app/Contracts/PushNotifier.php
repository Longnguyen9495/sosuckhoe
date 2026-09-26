<?php

namespace App\Contracts;

interface PushNotifier
{
    /**
     * Gửi thông báo push.
     *
     * @param string[] $recipients Danh sách endpoint hoặc subscription key
     * @param string $title Tiêu đề thông báo
     * @param string $body Nội dung thông báo
     * @param array $data Payload phụ trợ
     * @return array{sent: int, failed: int}
     */
    public function send(array $recipients, string $title, string $body, array $data = []): array;
}
