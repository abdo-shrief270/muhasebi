<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Billing\Enums\InvoiceType;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\InvoiceLine;
use App\Domain\Billing\Models\InvoiceSettings;
use App\Domain\Billing\Models\RecurringInvoice;
use App\Domain\Webhook\Services\WebhookService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class RecurringInvoiceService
{
    public function __construct(
        private readonly InvoiceService $invoiceService,
    ) {}

    /**
     * Process all due recurring invoices.
     * Called by the scheduler every day.
     */
    public function processDue(): int
    {
        $due = RecurringInvoice::due()->with('client')->get();
        $generated = 0;

        foreach ($due as $recurring) {
            try {
                $this->generateInvoice($recurring);
                $generated++;
            } catch (\Throwable $e) {
                Log::error("Failed to generate recurring invoice #{$recurring->id}", [
                    'tenant_id' => $recurring->tenant_id,
                    'client_id' => $recurring->client_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $generated;
    }

    /**
     * Generate a single invoice from a recurring schedule.
     */
    public function generateInvoice(RecurringInvoice $recurring): Invoice
    {
        return DB::transaction(function () use ($recurring) {
            // Bind tenant context for the invoice service
            app()->instance('tenant.id', $recurring->tenant_id);

            $settings = InvoiceSettings::where('tenant_id', $recurring->tenant_id)->first()
                ?? new InvoiceSettings(['tenant_id' => $recurring->tenant_id]);

            // Create invoice
            $invoice = Invoice::create([
                'tenant_id' => $recurring->tenant_id,
                'client_id' => $recurring->client_id,
                'type' => 'invoice',
                'invoice_number' => $this->invoiceService->generateInvoiceNumber($settings, InvoiceType::Invoice),
                'date' => now()->toDateString(),
                'due_date' => now()->addDays($recurring->due_days)->toDateString(),
                'status' => 'draft',
                'currency' => $recurring->currency,
                'notes' => $recurring->notes,
                'terms' => $recurring->terms,
                'created_by' => $recurring->created_by,
            ]);

            // Create lines from template
            foreach ($recurring->line_items as $index => $item) {
                $quantity = (float) ($item['quantity'] ?? 0);
                $unitPrice = (float) ($item['unit_price'] ?? 0);

                if ($quantity <= 0 || $unitPrice < 0) {
                    throw ValidationException::withMessages([
                        "line_items.{$index}" => ['Invalid quantity or unit price.'],
                    ]);
                }

                $line = new InvoiceLine([
                    'invoice_id' => $invoice->id,
                    'description' => $item['description'] ?? '',
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'discount_percent' => $item['discount_percent'] ?? 0,
                    'vat_rate' => $item['vat_rate'] ?? (float) config('tax.vat_rate', '14.00'),
                    'account_id' => $item['account_id'] ?? null,
                    'sort_order' => $index,
                ]);
                $line->calculateTotals();
                $line->save();
            }

            // Recalculate invoice totals
            $this->invoiceService->recalculateTotals($invoice);

            // Auto-send if configured
            if ($recurring->auto_send) {
                $this->invoiceService->send($invoice);
            }

            // Update recurring schedule
            $recurring->update([
                'last_run_date' => now()->toDateString(),
                'next_run_date' => $recurring->calculateNextRunDate(),
                'invoices_generated' => $recurring->invoices_generated + 1,
            ]);

            // Auto-deactivate if limit reached or expired
            if ($recurring->hasReachedLimit() || $recurring->hasExpired()) {
                $recurring->update(['is_active' => false]);
            }

            // Webhook
            WebhookService::dispatch($recurring->tenant_id, 'invoice.created', [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'recurring_invoice_id' => $recurring->id,
                'auto_generated' => true,
            ]);

            return $invoice->load('lines', 'client');
        });
    }

    // ── CRUD ──────────────────────────────────────────────────

    public function list(array $filters = []): LengthAwarePaginator
    {
        $query = RecurringInvoice::with('client:id,name')
            ->latest('created_at');

        if (! empty($filters['is_active'])) {
            $query->where('is_active', $filters['is_active'] === 'true');
        }

        if (! empty($filters['client_id'])) {
            $query->where('client_id', $filters['client_id']);
        }

        return $query->paginate($filters['per_page'] ?? 15);
    }

    public function create(array $data): RecurringInvoice
    {
        $data['next_run_date'] = $data['next_run_date'] ?? $data['start_date'];

        return RecurringInvoice::create($data);
    }

    public function update(RecurringInvoice $recurring, array $data): RecurringInvoice
    {
        $recurring->update($data);

        return $recurring->fresh();
    }
}
