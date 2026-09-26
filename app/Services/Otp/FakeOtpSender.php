<?php

namespace App\Services\Otp;

use App\Contracts\OtpSender;

final class FakeOtpSender implements OtpSender
{
    public function send(string $recipient, string $code): void
    {
        // Intentionally no-op. Never write OTPs or recipients to application logs.
    }
}
