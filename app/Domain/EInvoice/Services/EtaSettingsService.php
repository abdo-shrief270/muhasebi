<?php

declare(strict_types=1);

namespace App\Domain\EInvoice\Services;

use App\Domain\EInvoice\Models\EtaSettings;
use Illuminate\Validation\ValidationException;

class EtaSettingsService
{
    /**
     * Get or create ETA settings for the current tenant.
     */
    public function getSettings(?int $tenantId = null): EtaSettings
    {
        $tenantId ??= (int) app('tenant.id');

        return EtaSettings::withoutGlobalScopes()
            ->firstOrCreate(
                ['tenant_id' => $tenantId],
                [
                    'is_enabled' => false,
                    'environment' => 'preprod',
                    'branch_id' => '0',
                    'branch_address_country' => 'EG',
                ],
            );
    }

    /**
     * Update ETA settings.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateSettings(array $data): EtaSettings
    {
        $settings = $this->getSettings();
        $settings->update($data);

        // Clear cached token when credentials change
        if (isset($data['client_id']) || isset($data['client_secret']) || isset($data['environment'])) {
            $settings->update([
                'access_token' => null,
                'token_expires_at' => null,
            ]);
        }

        return $settings->refresh();
    }

    /**
     * Check if ETA integration is enabled for the current tenant.
     */
    public function isEnabled(?int $tenantId = null): bool
    {
        return $this->getSettings($tenantId)->is_enabled;
    }

    /**
     * Ensure ETA is enabled or throw a validation exception.
     *
     * @throws ValidationException
     */
    public function ensureEnabled(): EtaSettings
    {
        $settings = $this->getSettings();

        if (! $settings->is_enabled) {
            throw ValidationException::withMessages([
                'eta' => [
                    'ETA e-invoicing is not enabled for this tenant.',
                    'الفوترة الإلكترونية غير مفعّلة لهذا الحساب.',
                ],
            ]);
        }

        if (! $settings->client_id || ! $settings->client_secret) {
            throw ValidationException::withMessages([
                'eta' => [
                    'ETA API credentials are not configured.',
                    'لم يتم تكوين بيانات اعتماد الفوترة الإلكترونية.',
                ],
            ]);
        }

        return $settings;
    }
}
