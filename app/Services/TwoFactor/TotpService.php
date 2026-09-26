<?php

namespace App\Services\TwoFactor;

use App\Models\User;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use OTPHP\TOTP;

final class TotpService
{
    public function generateSecret(): string
    {
        return TOTP::create()->getSecret() ?? '';
    }

    public function qrCodeSvg(string $secret, string $label): string
    {
        $totp = TOTP::create($secret);
        $totp->setLabel($label);
        $totp->setIssuer(config('app.name', 'Sổ sức khỏe'));

        $renderer = new ImageRenderer(
            new RendererStyle(400),
            new SvgImageBackEnd()
        );
        $writer = new Writer($renderer);

        return $writer->writeString($totp->getProvisioningUri());
    }

    public function verify(string $secret, string $code): bool
    {
        if ($secret === '' || $code === '') {
            return false;
        }

        return TOTP::create($secret)->verify($code, null, 1);
    }

    public function generateRecoveryCodes(int $count = 8): array
    {
        return collect(range(1, $count))
            ->map(fn () => Str::upper(Str::random(8).'-'.Str::random(8)))
            ->values()
            ->all();
    }

    public function encryptRecoveryCodes(array $codes): string
    {
        return Crypt::encryptString(json_encode($codes));
    }

    public function decryptRecoveryCodes(?string $payload): array
    {
        if ($payload === null) {
            return [];
        }

        try {
            return json_decode(Crypt::decryptString($payload), true) ?? [];
        } catch (\Throwable) {
            return [];
        }
    }

    public function storeSecret(User $user, string $secret): void
    {
        $user->forceFill([
            'two_factor_secret' => Crypt::encryptString($secret),
            'two_factor_confirmed_at' => null,
            'two_factor_recovery_codes' => null,
        ])->save();
    }

    public function confirm(User $user, string $code): bool
    {
        $secret = $this->decryptSecret($user);

        if (! $this->verify($secret, $code)) {
            return false;
        }

        $codes = $this->generateRecoveryCodes();
        $user->forceFill([
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => $this->encryptRecoveryCodes($codes),
        ])->save();

        return true;
    }

    public function decryptSecret(User $user): string
    {
        $encrypted = $user->two_factor_secret;

        if ($encrypted === null) {
            return '';
        }

        try {
            return Crypt::decryptString($encrypted);
        } catch (\Throwable) {
            return '';
        }
    }

    public function validateChallenge(User $user, string $code): bool
    {
        $secret = $this->decryptSecret($user);

        return $this->verify($secret, $code);
    }

    public function validateRecoveryCode(User $user, string $code): bool
    {
        $codes = $this->decryptRecoveryCodes($user->two_factor_recovery_codes);
        $normalized = strtoupper(str_replace(' ', '-', trim($code)));

        if (! in_array($normalized, $codes, true)) {
            return false;
        }

        $remaining = array_values(array_filter($codes, fn (string $c) => $c !== $normalized));
        $user->forceFill([
            'two_factor_recovery_codes' => $this->encryptRecoveryCodes($remaining),
        ])->save();

        return true;
    }
}
