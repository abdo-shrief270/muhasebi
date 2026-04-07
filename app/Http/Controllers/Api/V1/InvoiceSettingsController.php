<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Billing\Services\InvoiceService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Invoice\UpdateInvoiceSettingsRequest;
use App\Http\Resources\InvoiceSettingsResource;

class InvoiceSettingsController extends Controller
{
    public function __construct(
        private readonly InvoiceService $invoiceService,
    ) {}

    public function show(): InvoiceSettingsResource
    {
        $settings = $this->invoiceService->getSettings();

        return new InvoiceSettingsResource(
            $settings->load(['arAccount', 'revenueAccount', 'vatAccount'])
        );
    }

    public function update(UpdateInvoiceSettingsRequest $request): InvoiceSettingsResource
    {
        $settings = $this->invoiceService->updateSettings($request->validated());

        return new InvoiceSettingsResource(
            $settings->load(['arAccount', 'revenueAccount', 'vatAccount'])
        );
    }
}
