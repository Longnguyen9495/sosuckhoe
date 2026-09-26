<?php

namespace App\Providers;

use App\Contracts\AiOcrClient;
use App\Contracts\OtpSender;
use App\Contracts\PushNotifier;
use App\Contracts\SmsSender;
use App\Contracts\ZaloZnsSender;
use App\Models\Document;
use App\Policies\DocumentPolicy;
use App\Contracts\MedicalAiClient;
use App\Services\Ai\FakeAiOcrClient;
use App\Services\Ai\FakeMedicalAiClient;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\Otp\FakeOtpSender;
use App\Services\Push\FakePushNotifier;
use App\Services\Sms\FakeSmsSender;
use App\Services\Zalo\FakeZaloZnsSender;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(TenantContext::class, fn () => new TenantContext());
        $this->app->bind(OtpSender::class, function (): OtpSender {
            $driver = config('services.otp.driver');

            if ($driver === 'fake' && ! app()->environment('local', 'testing')) {
                throw new InvalidArgumentException(
                    'OTP driver "fake" is only permitted in local or testing environments.'
                );
            }

            return match ($driver) {
                'fake' => new FakeOtpSender(),
                default => throw new InvalidArgumentException('Unsupported OTP driver.'),
            };
        });
        $this->app->bind(PushNotifier::class, function (): PushNotifier {
            return match (config('services.push.driver', 'fake')) {
                'fake' => new FakePushNotifier(),
                default => throw new InvalidArgumentException('Unsupported push driver.'),
            };
        });
        $this->app->singleton(OpenAiCompatibleClient::class, fn (): OpenAiCompatibleClient => new OpenAiCompatibleClient(
            (string) config('services.ai.base_url'),
            (string) config('services.ai.api_key'),
            (string) config('services.ai.model'),
            (int) config('services.ai.timeout', 150),
        ));
        $this->app->bind(MedicalAiClient::class, function (): MedicalAiClient {
            return match (config('services.ai.driver', 'fake')) {
                'fake' => new FakeMedicalAiClient(),
                'openai' => app(OpenAiCompatibleClient::class),
                default => throw new InvalidArgumentException('Unsupported AI driver.'),
            };
        });
        $this->app->bind(AiOcrClient::class, function (): AiOcrClient {
            return match (config('services.ai.driver', 'fake')) {
                'fake' => new FakeAiOcrClient(),
                'openai' => app(OpenAiCompatibleClient::class),
                default => throw new InvalidArgumentException('Unsupported AI OCR driver.'),
            };
        });
        $this->app->bind(ZaloZnsSender::class, function (): ZaloZnsSender {
            return match (config('services.zalo.driver', 'fake')) {
                'fake' => new FakeZaloZnsSender(),
                default => throw new InvalidArgumentException('Unsupported Zalo driver.'),
            };
        });
        $this->app->bind(SmsSender::class, function (): SmsSender {
            return match (config('services.sms.driver', 'fake')) {
                'fake' => new FakeSmsSender(),
                default => throw new InvalidArgumentException('Unsupported SMS driver.'),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Document::class, DocumentPolicy::class);
    }
}
