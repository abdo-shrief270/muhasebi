<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Shared\Services\ExportService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController extends Controller
{
    /**
     * Export clients.
     *
     * GET /export/clients?format=csv|excel
     */
    public function clients(Request $request): StreamedResponse
    {
        $tenantId = app('tenant.id');

        $query = \App\Domain\Client\Models\Client::where('tenant_id', $tenantId)
            ->orderBy('name');

        $headers = ['ID', 'Name', 'Email', 'Phone', 'Tax ID', 'Address', 'Created At'];
        $rowMapper = fn ($client) => [
            $client->id,
            $client->name,
            $client->email ?? '',
            $client->phone ?? '',
            $client->tax_id ?? '',
            $client->address ?? '',
            $client->created_at?->format('Y-m-d'),
        ];
        $date = now()->format('Y-m-d');

        if ($request->query('format') === 'excel') {
            return ExportService::streamExcel($query, $headers, $rowMapper, "clients_{$date}.xls");
        }

        return ExportService::streamCsv($query, $headers, $rowMapper, "clients_{$date}.csv");
    }

    /**
     * Export invoices.
     *
     * GET /export/invoices?from=2026-01-01&to=2026-12-31&format=csv|excel
     */
    public function invoices(Request $request): StreamedResponse
    {
        $tenantId = app('tenant.id');

        $query = \App\Domain\Billing\Models\Invoice::where('tenant_id', $tenantId)
            ->with(['client:id,name'])
            ->orderByDesc('created_at');

        if ($request->filled('from')) {
            $query->where('created_at', '>=', $request->input('from'));
        }
        if ($request->filled('to')) {
            $query->where('created_at', '<=', $request->input('to'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $headers = ['Invoice #', 'Client', 'Date', 'Due Date', 'Subtotal', 'Tax', 'Total', 'Status', 'Paid Amount'];
        $rowMapper = fn ($inv) => [
            $inv->invoice_number ?? $inv->id,
            $inv->client?->name ?? '',
            $inv->created_at?->format('Y-m-d'),
            $inv->due_date?->format('Y-m-d') ?? '',
            $inv->subtotal ?? 0,
            $inv->tax_amount ?? 0,
            $inv->total ?? 0,
            $inv->status ?? '',
            $inv->paid_amount ?? 0,
        ];
        $date = now()->format('Y-m-d');

        if ($request->query('format') === 'excel') {
            return ExportService::streamExcel($query, $headers, $rowMapper, "invoices_{$date}.xls");
        }

        return ExportService::streamCsv($query, $headers, $rowMapper, "invoices_{$date}.csv");
    }

    /**
     * Export journal entries.
     *
     * GET /export/journal-entries?from=2026-01-01&to=2026-12-31&format=csv|excel
     */
    public function journalEntries(Request $request): StreamedResponse
    {
        $tenantId = app('tenant.id');

        $query = \App\Domain\Accounting\Models\JournalEntry::where('tenant_id', $tenantId)
            ->with(['lines.account:id,name_ar,name_en,code'])
            ->orderByDesc('date');

        if ($request->filled('from')) {
            $query->where('date', '>=', $request->input('from'));
        }
        if ($request->filled('to')) {
            $query->where('date', '<=', $request->input('to'));
        }

        $headers = ['Entry #', 'Date', 'Description', 'Account Code', 'Account Name', 'Debit', 'Credit'];
        $rowMapper = function ($entry) {
            $rows = [];
            foreach ($entry->lines as $line) {
                $rows[] = [
                    $entry->entry_number ?? $entry->id,
                    $entry->date?->format('Y-m-d') ?? '',
                    $entry->description ?? '',
                    $line->account?->code ?? '',
                    $line->account?->name_en ?? $line->account?->name_ar ?? '',
                    $line->debit ?? 0,
                    $line->credit ?? 0,
                ];
            }

            return $rows[0] ?? [$entry->id, '', '', '', '', 0, 0];
        };
        $date = now()->format('Y-m-d');

        if ($request->query('format') === 'excel') {
            return ExportService::streamExcel($query, $headers, $rowMapper, "journal_entries_{$date}.xls");
        }

        return ExportService::streamCsv($query, $headers, $rowMapper, "journal_entries_{$date}.csv");
    }
}
