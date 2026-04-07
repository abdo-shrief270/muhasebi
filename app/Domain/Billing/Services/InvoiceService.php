<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Services\JournalEntryService;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Enums\InvoiceType;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\InvoiceLine;
use App\Domain\Billing\Models\InvoiceSettings;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvoiceService
{
    public function __construct(
        private readonly JournalEntryService $journalEntryService,
    ) {}

    /**
     * List invoices with search, filter, and pagination.
     *
     * @param  array<string, mixed>  $filters
     */
    public function list(array $filters = []): LengthAwarePaginator
    {
        return Invoice::query()
            ->with(['client', 'lines'])
            ->when(
                isset($filters['search']),
                fn ($q) => $q->search($filters['search'])
            )
            ->when(
                isset($filters['status']),
                fn ($q) => $q->ofStatus(
                    $filters['status'] instanceof InvoiceStatus
                        ? $filters['status']
                        : InvoiceStatus::from($filters['status'])
                )
            )
            ->when(
                isset($filters['type']),
                fn ($q) => $q->ofType(
                    $filters['type'] instanceof InvoiceType
                        ? $filters['type']
                        : InvoiceType::from($filters['type'])
                )
            )
            ->when(isset($filters['client_id']), fn ($q) => $q->forClient((int) $filters['client_id']))
            ->when(isset($filters['date_from']), fn ($q) => $q->where('date', '>=', $filters['date_from']))
            ->when(isset($filters['date_to']), fn ($q) => $q->where('date', '<=', $filters['date_to']))
            ->when(
                isset($filters['overdue']) && $filters['overdue'],
                fn ($q) => $q->overdue()
            )
            ->orderBy('date', 'desc')
            ->orderBy('invoice_number', 'desc')
            ->paginate($filters['per_page'] ?? 15);
    }

    /**
     * Create a new invoice with lines inside a transaction.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function create(array $data): Invoice
    {
        return DB::transaction(function () use ($data): Invoice {
            $settings = $this->getSettings();
            $type = isset($data['type'])
                ? ($data['type'] instanceof InvoiceType ? $data['type'] : InvoiceType::from($data['type']))
                : InvoiceType::Invoice;

            $invoiceNumber = $this->generateInvoiceNumber($settings, $type);

            $dueDate = $data['due_date'] ?? null;

            if (! $dueDate && isset($data['date'])) {
                $dueDate = now()->parse($data['date'])->addDays($settings->default_due_days)->toDateString();
            }

            $invoice = Invoice::query()->create([
                'client_id' => $data['client_id'],
                'type' => $type,
                'invoice_number' => $invoiceNumber,
                'date' => $data['date'],
                'due_date' => $dueDate,
                'status' => InvoiceStatus::Draft,
                'currency' => $data['currency'] ?? 'EGP',
                'notes' => $data['notes'] ?? $settings->default_notes,
                'terms' => $data['terms'] ?? $settings->default_payment_terms,
                'original_invoice_id' => $data['original_invoice_id'] ?? null,
                'created_by' => Auth::id(),
            ]);

            $this->createLines($invoice, $data['lines'] ?? []);
            $this->recalculateTotals($invoice);

            return $invoice->load(['client', 'lines']);
        });
    }

    /**
     * Update a draft invoice with new data and lines.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function update(Invoice $invoice, array $data): Invoice
    {
        if (! $invoice->status->canEdit()) {
            throw ValidationException::withMessages([
                'status' => ['Only draft invoices can be edited.'],
            ]);
        }

        return DB::transaction(function () use ($invoice, $data): Invoice {
            $invoice->update([
                'client_id' => $data['client_id'] ?? $invoice->client_id,
                'date' => $data['date'] ?? $invoice->date,
                'due_date' => $data['due_date'] ?? $invoice->due_date,
                'currency' => $data['currency'] ?? $invoice->currency,
                'notes' => $data['notes'] ?? $invoice->notes,
                'terms' => $data['terms'] ?? $invoice->terms,
            ]);

            if (isset($data['lines'])) {
                $invoice->lines()->delete();
                $this->createLines($invoice, $data['lines']);
            }

            $this->recalculateTotals($invoice);

            return $invoice->refresh()->load(['client', 'lines']);
        });
    }

    /**
     * Show an invoice with all related data loaded.
     */
    public function show(Invoice $invoice): Invoice
    {
        return $invoice->load([
            'client',
            'lines.account',
            'payments',
            'journalEntry',
            'createdByUser',
            'creditNotes',
        ]);
    }

    /**
     * Soft-delete a draft invoice.
     *
     * @throws ValidationException
     */
    public function delete(Invoice $invoice): void
    {
        if (! $invoice->status->canEdit()) {
            throw ValidationException::withMessages([
                'status' => ['Only draft invoices can be deleted.'],
            ]);
        }

        $invoice->delete();
    }

    /**
     * Send a draft invoice (mark as sent).
     *
     * @throws ValidationException
     */
    public function send(Invoice $invoice): Invoice
    {
        if (! $invoice->status->canSend()) {
            throw ValidationException::withMessages([
                'status' => ['Only draft invoices can be sent.'],
            ]);
        }

        $invoice->update([
            'status' => InvoiceStatus::Sent,
            'sent_at' => now(),
        ]);

        return $invoice->refresh();
    }

    /**
     * Cancel an invoice. Reverses journal entry if one exists.
     *
     * @throws ValidationException
     */
    public function cancel(Invoice $invoice): Invoice
    {
        if (! $invoice->status->canCancel()) {
            throw ValidationException::withMessages([
                'status' => ['This invoice cannot be cancelled in its current status.'],
            ]);
        }

        return DB::transaction(function () use ($invoice): Invoice {
            $invoice->update([
                'status' => InvoiceStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => Auth::id(),
            ]);

            // Reverse the journal entry if one was posted
            if ($invoice->journal_entry_id) {
                $journalEntry = $invoice->journalEntry;

                if ($journalEntry) {
                    $this->journalEntryService->reverse($journalEntry);
                }
            }

            return $invoice->refresh();
        });
    }

    /**
     * Post an invoice to the General Ledger by creating and posting a journal entry.
     *
     * @throws ValidationException
     */
    public function postToGL(Invoice $invoice): Invoice
    {
        if ($invoice->journal_entry_id) {
            throw ValidationException::withMessages([
                'journal_entry' => ['This invoice has already been posted to the General Ledger.'],
            ]);
        }

        if (! in_array($invoice->status, [InvoiceStatus::Sent, InvoiceStatus::PartiallyPaid, InvoiceStatus::Overdue], true)) {
            throw ValidationException::withMessages([
                'status' => ['Only sent, partially paid, or overdue invoices can be posted to the General Ledger.'],
            ]);
        }

        return DB::transaction(function () use ($invoice): Invoice {
            $invoice->load('lines');
            $settings = $this->getSettings();
            $tenantId = (int) app('tenant.id');

            // Resolve account IDs
            $arAccountId = $settings->ar_account_id
                ?? $this->resolveAccountByCode(config('accounting.default_accounts.accounts_receivable'), $tenantId);

            $vatAccountId = $settings->vat_account_id
                ?? $this->resolveAccountByCode(config('accounting.default_accounts.vat_output'), $tenantId);

            // Build journal entry lines
            $jeLines = [];
            $currency = $invoice->currency ?? 'EGP';

            // Debit: Accounts Receivable for the invoice total
            $jeLines[] = [
                'account_id' => $arAccountId,
                'debit' => (float) $invoice->total,
                'credit' => 0,
                'currency' => $currency,
                'description' => "فاتورة رقم {$invoice->invoice_number}",
            ];

            // Credit: Revenue — group by account_id from lines
            $revenueByAccount = [];
            $defaultRevenueAccountId = $settings->revenue_account_id
                ?? $this->resolveAccountByCode(config('accounting.default_accounts.revenue'), $tenantId);

            foreach ($invoice->lines as $line) {
                $accountId = $line->account_id ?? $defaultRevenueAccountId;
                $revenueByAccount[$accountId] = ($revenueByAccount[$accountId] ?? 0) + (float) $line->line_total;
            }

            foreach ($revenueByAccount as $accountId => $amount) {
                $jeLines[] = [
                    'account_id' => $accountId,
                    'debit' => 0,
                    'credit' => $amount,
                    'currency' => $currency,
                    'description' => "فاتورة رقم {$invoice->invoice_number}",
                ];
            }

            // Credit: VAT Payable for the total VAT
            if ((float) $invoice->vat_amount > 0) {
                $jeLines[] = [
                    'account_id' => $vatAccountId,
                    'debit' => 0,
                    'credit' => (float) $invoice->vat_amount,
                    'currency' => $currency,
                    'description' => "ضريبة القيمة المضافة - فاتورة رقم {$invoice->invoice_number}",
                ];
            }

            // Create and post the journal entry
            $journalEntry = $this->journalEntryService->create([
                'date' => $invoice->date->toDateString(),
                'description' => "فاتورة رقم {$invoice->invoice_number}",
                'reference' => $invoice->invoice_number,
                'lines' => $jeLines,
            ]);

            $this->journalEntryService->post($journalEntry);

            $invoice->update([
                'journal_entry_id' => $journalEntry->id,
            ]);

            return $invoice->refresh()->load('journalEntry');
        });
    }

    /**
     * Create a credit note against an existing invoice.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function createCreditNote(Invoice $originalInvoice, array $data): Invoice
    {
        if (! in_array($originalInvoice->status, [InvoiceStatus::Sent, InvoiceStatus::Paid, InvoiceStatus::PartiallyPaid, InvoiceStatus::Overdue], true)) {
            throw ValidationException::withMessages([
                'status' => ['Credit notes can only be created for sent, paid, partially paid, or overdue invoices.'],
            ]);
        }

        $data['type'] = InvoiceType::CreditNote;
        $data['original_invoice_id'] = $originalInvoice->id;
        $data['client_id'] = $data['client_id'] ?? $originalInvoice->client_id;
        $data['date'] = $data['date'] ?? now()->toDateString();
        $data['currency'] = $data['currency'] ?? $originalInvoice->currency;

        return $this->create($data);
    }

    /**
     * Get or create invoice settings for the current tenant.
     */
    public function getSettings(): InvoiceSettings
    {
        return InvoiceSettings::query()->firstOrCreate(
            ['tenant_id' => (int) app('tenant.id')],
            [
                'invoice_prefix' => 'INV',
                'credit_note_prefix' => 'CN',
                'debit_note_prefix' => 'DN',
                'next_invoice_number' => 1,
                'next_credit_note_number' => 1,
                'next_debit_note_number' => 1,
                'default_due_days' => 30,
                'default_vat_rate' => 14.00,
            ]
        );
    }

    /**
     * Update invoice settings for the current tenant.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateSettings(array $data): InvoiceSettings
    {
        $settings = $this->getSettings();

        $settings->update($data);

        return $settings->refresh();
    }

    /**
     * Generate the next sequential invoice/credit-note/debit-note number.
     * Increments the appropriate counter in settings.
     */
    public function generateInvoiceNumber(InvoiceSettings $settings, InvoiceType $type): string
    {
        [$prefix, $nextNumberField] = match ($type) {
            InvoiceType::Invoice => [$settings->invoice_prefix, 'next_invoice_number'],
            InvoiceType::CreditNote => [$settings->credit_note_prefix, 'next_credit_note_number'],
            InvoiceType::DebitNote => [$settings->debit_note_prefix, 'next_debit_note_number'],
        };

        return DB::transaction(function () use ($settings, $nextNumberField, $prefix) {
            $settings = $settings->newQuery()
                ->where('id', $settings->id)
                ->lockForUpdate()
                ->first();

            $number = $settings->{$nextNumberField};
            $formatted = $prefix.'-'.str_pad((string) $number, 6, '0', STR_PAD_LEFT);
            $settings->increment($nextNumberField);

            return $formatted;
        });
    }

    /**
     * Recalculate invoice totals from its lines.
     */
    public function recalculateTotals(Invoice $invoice): Invoice
    {
        $invoice->load('lines');

        $subtotal = '0.00';
        $vatAmount = '0.00';

        foreach ($invoice->lines as $line) {
            $subtotal = bcadd($subtotal, (string) $line->line_total, 2);
            $vatAmount = bcadd($vatAmount, (string) $line->vat_amount, 2);
        }

        $discountAmount = (string) ($invoice->discount_amount ?? '0.00');
        $total = bcadd(bcsub($subtotal, $discountAmount, 2), $vatAmount, 2);

        $invoice->update([
            'subtotal' => $subtotal,
            'vat_amount' => $vatAmount,
            'total' => $total,
        ]);

        return $invoice->refresh();
    }

    /**
     * Create invoice line records and calculate their totals.
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function createLines(Invoice $invoice, array $lines): void
    {
        foreach ($lines as $index => $lineData) {
            $line = new InvoiceLine([
                'invoice_id' => $invoice->id,
                'description' => $lineData['description'] ?? '',
                'quantity' => $lineData['quantity'] ?? 1,
                'unit_price' => $lineData['unit_price'] ?? 0,
                'discount_percent' => $lineData['discount_percent'] ?? 0,
                'vat_rate' => $lineData['vat_rate'] ?? $this->getSettings()->default_vat_rate,
                'sort_order' => $lineData['sort_order'] ?? $index,
                'account_id' => $lineData['account_id'] ?? null,
            ]);

            $line->calculateTotals();

            $invoice->lines()->save($line);
        }
    }

    /**
     * Resolve an account ID by its code for the current tenant.
     *
     * @throws ValidationException
     */
    private function resolveAccountByCode(string $code, int $tenantId): int
    {
        $account = Account::query()
            ->forTenant($tenantId)
            ->where('code', $code)
            ->first();

        if (! $account) {
            throw ValidationException::withMessages([
                'account' => ["Required account with code '{$code}' not found. Please set up your chart of accounts."],
            ]);
        }

        return $account->id;
    }
}
