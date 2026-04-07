<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\InvoiceReminder;
use App\Domain\Billing\Models\ReminderSetting;
use App\Domain\Communication\Services\SmsService;
use App\Domain\Integration\Services\BeonChatService;
use App\Mail\DynamicTemplateMail;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class AgingReminderService
{
    /**
     * Process aging reminders for a tenant.
     *
     * @return array{sent: int, skipped: int, errors: array}
     */
    public function processForTenant(int $tenantId): array
    {
        app()->instance('tenant.id', $tenantId);

        $settings = ReminderSetting::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->first();

        if (! $settings || ! $settings->is_enabled) {
            return ['sent' => 0, 'skipped' => 0, 'errors' => []];
        }

        $milestones = $settings->milestones ?? [30, 60, 90];
        $channels = $settings->channels ?? ['email'];
        $today = now();

        $sent = 0;
        $skipped = 0;
        $errors = [];

        // Find overdue invoices
        $invoices = Invoice::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', [InvoiceStatus::Sent, InvoiceStatus::PartiallyPaid, InvoiceStatus::Overdue])
            ->whereNotNull('due_date')
            ->where('due_date', '<', $today)
            ->with('client')
            ->get();

        foreach ($invoices as $invoice) {
            $daysOverdue = (int) $invoice->due_date->diffInDays($today, false);
            $balanceDue = $invoice->balanceDue();

            if ($balanceDue <= 0) {
                continue;
            }

            // Check which milestone this falls on
            $milestone = $this->matchMilestone($daysOverdue, $milestones);

            if ($milestone === null) {
                continue;
            }

            // Check if we already sent for this milestone
            $alreadySent = InvoiceReminder::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('invoice_id', $invoice->id)
                ->where('milestone', (string) $milestone)
                ->exists();

            if ($alreadySent) {
                $skipped++;

                continue;
            }

            // Send reminders
            $channelsSent = [];
            $error = null;

            try {
                $data = [
                    'client_name' => $invoice->client?->name ?? '',
                    'invoice_number' => $invoice->invoice_number,
                    'amount' => number_format($balanceDue, 2),
                    'due_date' => $invoice->due_date->format('Y/m/d'),
                    'days_overdue' => $daysOverdue,
                    'milestone' => $milestone,
                ];

                $templateKey = $this->getTemplateKey($milestone);

                // Email
                if (in_array('email', $channels) && $invoice->client?->email) {
                    $recipients = [$invoice->client->email];

                    // CC escalation email for 90+ days
                    if ($milestone >= 90 && $settings->escalation_email) {
                        $recipients[] = $settings->escalation_email;
                    }

                    Mail::to($recipients)->send(new DynamicTemplateMail(
                        $templateKey,
                        'ar',
                        array_merge($data, ['subject' => "تذكير بدفع فاتورة متأخرة {$milestone} يوم"]),
                    ));

                    $channelsSent[] = 'email';
                }

                // WhatsApp
                if (in_array('whatsapp', $channels) && $invoice->client?->phone) {
                    $phone = $settings->send_to_contact_person && $invoice->client?->contact_phone
                        ? $invoice->client->contact_phone
                        : $invoice->client->phone;

                    if (BeonChatService::isConfigured()) {
                        $message = "تذكير: فاتورة رقم {$invoice->invoice_number} متأخرة {$daysOverdue} يوم. المبلغ المستحق: {$data['amount']} ج.م. يرجى السداد في أقرب وقت.";

                        BeonChatService::sendWhatsApp($phone, $message);
                        $channelsSent[] = 'whatsapp';
                    }
                }

                // SMS
                if (in_array('sms', $channels) && $invoice->client?->phone) {
                    $phone = $invoice->client->phone;
                    $message = "تذكير: فاتورة {$invoice->invoice_number} متأخرة {$daysOverdue} يوم. المبلغ: {$data['amount']} ج.م";

                    SmsService::send($phone, $message);
                    $channelsSent[] = 'sms';
                }
            } catch (\Throwable $e) {
                $error = $e->getMessage();
                $errors[] = [
                    'invoice_id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'error' => $error,
                ];
                Log::warning('Aging reminder failed', [
                    'tenant_id' => $tenantId,
                    'invoice_id' => $invoice->id,
                    'error' => $error,
                ]);
            }

            // Record the reminder
            InvoiceReminder::withoutGlobalScopes()->create([
                'tenant_id' => $tenantId,
                'invoice_id' => $invoice->id,
                'client_id' => $invoice->client_id,
                'days_overdue' => $daysOverdue,
                'milestone' => (string) $milestone,
                'channels_sent' => $channelsSent,
                'status' => empty($error) ? 'sent' : 'failed',
                'error' => $error,
                'sent_at' => now(),
            ]);

            if (empty($error)) {
                $sent++;
            }
        }

        return compact('sent', 'skipped', 'errors');
    }

    /**
     * Get reminder history for an invoice.
     */
    public function historyForInvoice(int $invoiceId): array
    {
        return InvoiceReminder::query()
            ->where('invoice_id', $invoiceId)
            ->orderByDesc('sent_at')
            ->get()
            ->toArray();
    }

    /**
     * List all reminder history with filters.
     *
     * @param  array<string, mixed>  $filters
     */
    public function listHistory(array $filters = []): LengthAwarePaginator
    {
        return InvoiceReminder::query()
            ->with(['invoice:id,invoice_number,total,amount_paid,due_date', 'client:id,name'])
            ->when(isset($filters['client_id']), fn ($q) => $q->where('client_id', $filters['client_id']))
            ->when(isset($filters['milestone']), fn ($q) => $q->where('milestone', $filters['milestone']))
            ->when(isset($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(isset($filters['from']), fn ($q) => $q->where('sent_at', '>=', $filters['from']))
            ->when(isset($filters['to']), fn ($q) => $q->where('sent_at', '<=', $filters['to'] . ' 23:59:59'))
            ->orderByDesc('sent_at')
            ->paginate($filters['per_page'] ?? 20);
    }

    /**
     * Match days overdue to the closest milestone (exact day match with tolerance).
     */
    private function matchMilestone(int $daysOverdue, array $milestones): ?int
    {
        foreach ($milestones as $milestone) {
            // Trigger on the exact milestone day (with 1-day tolerance for weekends)
            if ($daysOverdue >= $milestone && $daysOverdue <= $milestone + 1) {
                return (int) $milestone;
            }
        }

        return null;
    }

    /**
     * Get email template key based on milestone.
     */
    private function getTemplateKey(int $milestone): string
    {
        return match (true) {
            $milestone >= 90 => 'aging_reminder_final',
            $milestone >= 60 => 'aging_reminder_urgent',
            default => 'aging_reminder_standard',
        };
    }
}
