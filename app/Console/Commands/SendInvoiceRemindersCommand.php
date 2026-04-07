<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Integration\Services\BeonChatService;
use App\Mail\DynamicTemplateMail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Sends payment reminders for upcoming and overdue invoices.
 *
 * Schedule:
 * - 7 days before due: gentle reminder
 * - 3 days before due: upcoming reminder
 * - 1 day before due: urgent reminder
 * - On due date: final reminder
 * - 1 day overdue: overdue notice
 * - 7 days overdue: final overdue notice
 */
class SendInvoiceRemindersCommand extends Command
{
    protected $signature = 'invoices:send-reminders {--dry-run : Preview without sending}';

    protected $description = 'Send payment reminders for upcoming and overdue invoices';

    private const REMINDER_SCHEDULE = [
        ['days_before' => 7, 'type' => 'upcoming', 'subject_ar' => 'تذكير: فاتورة مستحقة خلال 7 أيام', 'subject_en' => 'Reminder: Invoice due in 7 days'],
        ['days_before' => 3, 'type' => 'upcoming', 'subject_ar' => 'تذكير: فاتورة مستحقة خلال 3 أيام', 'subject_en' => 'Reminder: Invoice due in 3 days'],
        ['days_before' => 1, 'type' => 'urgent', 'subject_ar' => 'عاجل: فاتورة مستحقة غداً', 'subject_en' => 'Urgent: Invoice due tomorrow'],
        ['days_before' => 0, 'type' => 'due_today', 'subject_ar' => 'فاتورة مستحقة اليوم', 'subject_en' => 'Invoice due today'],
        ['days_before' => -1, 'type' => 'overdue', 'subject_ar' => 'فاتورة متأخرة السداد', 'subject_en' => 'Overdue invoice'],
        ['days_before' => -7, 'type' => 'final_overdue', 'subject_ar' => 'إشعار أخير: فاتورة متأخرة 7 أيام', 'subject_en' => 'Final notice: Invoice 7 days overdue'],
    ];

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $sent = 0;

        foreach (self::REMINDER_SCHEDULE as $schedule) {
            $targetDate = now()->addDays($schedule['days_before'])->toDateString();

            $invoices = Invoice::with(['client', 'tenant'])
                ->whereIn('status', [
                    InvoiceStatus::Sent,
                    InvoiceStatus::PartiallyPaid,
                    InvoiceStatus::Overdue,
                ])
                ->where('due_date', $targetDate)
                ->whereColumn('amount_paid', '<', 'total')
                ->get();

            foreach ($invoices as $invoice) {
                $client = $invoice->client;
                if (! $client?->email) continue;

                // Skip if already reminded today (prevent duplicate sends on re-run)
                $cacheKey = "invoice_reminder:{$invoice->id}:{$schedule['type']}";
                if (cache()->has($cacheKey)) continue;

                if ($dryRun) {
                    $this->line("  [DRY RUN] {$schedule['type']}: Invoice #{$invoice->invoice_number} → {$client->email}");
                    continue; // Don't increment $sent — it's not really sent
                }

                try {
                    // Send email
                    Mail::to($client->email)->send(new DynamicTemplateMail(
                        templateKey: "invoice_reminder_{$schedule['type']}",
                        locale: 'ar',
                        data: [
                            'client_name' => $client->name,
                            'invoice_number' => $invoice->invoice_number,
                            'amount' => number_format((float) $invoice->balanceDue(), 2) . ' ' . $invoice->currency,
                            'due_date' => $invoice->due_date->format('Y/m/d'),
                            'subject' => $schedule['subject_ar'],
                        ],
                        fallbackView: 'emails.invoice-reminder',
                    ));

                    // Send WhatsApp if available
                    if (BeonChatService::isConfigured() && $client->phone) {
                        BeonChatService::sendWhatsApp(
                            phone: $client->phone,
                            message: "{$schedule['subject_ar']}\nفاتورة رقم: {$invoice->invoice_number}\nالمبلغ المتبقي: " . number_format((float) $invoice->balanceDue(), 2) . " {$invoice->currency}",
                        );
                    }

                    // Mark as reminded (24hr cache)
                    cache()->put($cacheKey, true, 86400);
                    $sent++;

                    $this->info("  {$schedule['type']}: Invoice #{$invoice->invoice_number} → {$client->email}");
                } catch (\Throwable $e) {
                    $this->error("  Failed: Invoice #{$invoice->invoice_number} — {$e->getMessage()}");
                }
            }
        }

        $this->info("Sent {$sent} reminder(s).");

        return self::SUCCESS;
    }
}
