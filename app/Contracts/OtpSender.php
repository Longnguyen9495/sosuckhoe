<?php

namespace App\Contracts;

interface OtpSender
{
    public function send(string $recipient, string $code): void;
}
