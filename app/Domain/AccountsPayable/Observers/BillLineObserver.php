<?php

declare(strict_types=1);

namespace App\Domain\AccountsPayable\Observers;

use App\Domain\AccountsPayable\Models\BillLine;
use App\Domain\AccountsPayable\Models\VendorProduct;
use App\Domain\AccountsPayable\Services\VendorProductService;

/**
 * Mirror of InvoiceLineObserver on the AP side — bumps
 * `vendor_products.last_used_at` whenever a bill line is created with a
 * `vendor_product_id` set.
 *
 * Saved via `saveQuietly()` so we don't re-trigger model events and don't
 * pollute activity log with the recency-tracking bump itself.
 */
class BillLineObserver
{
    public function __construct(
        private readonly VendorProductService $vendorProductService,
    ) {}

    public function created(BillLine $line): void
    {
        if (! $line->vendor_product_id) {
            return;
        }

        $product = VendorProduct::query()->find($line->vendor_product_id);
        if ($product) {
            $this->vendorProductService->touchLastUsed($product);
        }
    }
}
