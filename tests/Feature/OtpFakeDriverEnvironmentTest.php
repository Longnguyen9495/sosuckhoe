<?php

namespace Tests\Feature;

use App\Contracts\OtpSender;
use App\Services\Otp\FakeOtpSender;
use InvalidArgumentException;
use Tests\TestCase;

class OtpFakeDriverEnvironmentTest extends TestCase
{
    public function test_fake_otp_driver_is_allowed_in_testing(): void
    {
        config()->set('services.otp.driver', 'fake');

        $sender = app(OtpSender::class);

        $this->assertInstanceOf(FakeOtpSender::class, $sender);
    }

    public function test_fake_otp_driver_is_rejected_in_production(): void
    {
        config()->set('services.otp.driver', 'fake');

        $originalEnv = $this->app->environment();
        $this->app->detectEnvironment(fn () => 'production');

        try {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('OTP driver "fake" is only permitted');

            app(OtpSender::class);
        } finally {
            $this->app->detectEnvironment(fn () => $originalEnv);
        }
    }
}
