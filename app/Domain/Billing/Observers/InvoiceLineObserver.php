<?php

declare(strict_types=1);

namespace App\Domain\Billing\Observers;

use App\Domain\Billing\Models\InvoiceLine;
use App\Domain\Client\Models\ClientProduct;
use App\Domain\Client\Services\ClientProductService;

/**
 * Bumps `client_products.last_used_at` whenever an invoice line is created
 * with a `client_product_id` set. Drives:
 *   - the LineItemsEditor picker's recent-first ordering, surfacing the
 *     items the user typically bills for the active client at the top
 *   - the catalog page's "Last used" column
 *
 * `saveQuietly()` inside touchLastUsed prevents this from re-triggering
 * other model events (no infinite loops, no duplicate activity log entries
 * for the bump itself).
 */
class InvoiceLineObserver
{
    public function __construct(
        private readonly ClientProductService $clientProductService,
    ) {}

    public function created(InvoiceLine $line): void
    {
        if (! $line->client_product_id) {
            return;
        }

        $product = ClientProduct::query()->find($line->client_product_id);
        if ($product) {
            $this->clientProductService->touchLastUsed($product);
        }
    }
}
