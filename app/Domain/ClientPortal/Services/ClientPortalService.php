<?php

declare(strict_types=1);

namespace App\Domain\ClientPortal\Services;

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Client\Models\Client;
use App\Domain\Document\Models\Document;
use App\Domain\Notification\Services\NotificationService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ClientPortalService
{
    public function __construct(
        private readonly NotificationService $notificationService,
    ) {}

    /**
     * Dashboard KPIs for the client portal.
     *
     * @return array<string, mixed>
     */
    public function dashboard(Client $client, int $userId): array
    {
        $invoiceQuery = Invoice::query()
            ->forClient($client->id)
            ->where('status', '!=', InvoiceStatus::Draft);

        $outstandingStatuses = [InvoiceStatus::Sent, InvoiceStatus::PartiallyPaid];

        $outstandingBalance = (float) (clone $invoiceQuery)
            ->whereIn('status', $outstandingStatuses)
            ->selectRaw('COALESCE(SUM(total - amount_paid), 0) as balance')
            ->value('balance');

        $overdueCount = (clone $invoiceQuery)
            ->whereIn('status', $outstandingStatuses)
            ->where('due_date', '<', today())
            ->count();

        $recentInvoices = (clone $invoiceQuery)
            ->with('lines')
            ->orderByDesc('date')
            ->limit(5)
            ->get();

        $recentDocuments = Document::query()
            ->forClient($client->id)
            ->active()
            ->orderByDesc('created_at')
            ->limit(5)
            ->get();

        $unreadNotifications = $this->notificationService->getUnreadCount($userId);

        return [
            'outstanding_balance' => $outstandingBalance,
            'overdue_invoices_count' => $overdueCount,
            'recent_invoices' => $recentInvoices,
            'recent_documents' => $recentDocuments,
            'unread_notifications_count' => $unreadNotifications,
        ];
    }

    /**
     * List non-draft invoices for the client.
     *
     * @param  array<string, mixed>  $filters
     */
    public function listInvoices(Client $client, array $filters = []): LengthAwarePaginator
    {
        return Invoice::query()
            ->forClient($client->id)
            ->where('status', '!=', InvoiceStatus::Draft)
            ->with(['lines', 'payments'])
            ->when(isset($filters['status']), fn ($q) => $q->ofStatus(InvoiceStatus::from($filters['status'])))
            ->when(
                isset($filters['from']) && isset($filters['to']),
                fn ($q) => $q->dateRange($filters['from'], $filters['to'])
            )
            ->when(isset($filters['search']), fn ($q) => $q->search($filters['search']))
            ->orderByDesc('date')
            ->paginate($filters['per_page'] ?? 15);
    }

    /**
     * Show a single invoice with validation.
     */
    public function showInvoice(Client $client, Invoice $invoice): Invoice
    {
        abort_if($invoice->client_id !== $client->id, 403, 'This invoice does not belong to your account.');
        abort_if($invoice->status === InvoiceStatus::Draft, 404);

        return $invoice->load(['lines', 'payments', 'client']);
    }

    /**
     * List documents for the client.
     *
     * @param  array<string, mixed>  $filters
     */
    public function listDocuments(Client $client, array $filters = []): LengthAwarePaginator
    {
        return Document::query()
            ->forClient($client->id)
            ->active()
            ->when(isset($filters['search']), fn ($q) => $q->search($filters['search']))
            ->when(isset($filters['category']), fn ($q) => $q->ofCategory($filters['category']))
            ->orderByDesc('created_at')
            ->paginate($filters['per_page'] ?? 15);
    }

    /**
     * Get client profile info.
     */
    public function profile(Client $client): Client
    {
        return $client;
    }
}
