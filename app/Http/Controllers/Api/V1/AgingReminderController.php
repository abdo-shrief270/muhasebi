<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Billing\Models\ReminderSetting;
use App\Domain\Billing\Services\AgingReminderService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgingReminderController extends Controller
{
    public function __construct(
        private readonly AgingReminderService $reminderService,
    ) {}

    /**
     * Get reminder settings for current tenant.
     */
    public function settings(): JsonResponse
    {
        return response()->json([
            'data' => ReminderSetting::forCurrentTenant(),
        ]);
    }

    /**
     * Update reminder settings.
     */
    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'milestones' => ['nullable', 'array'],
            'milestones.*' => ['integer', 'min:1', 'max:365'],
            'channels' => ['nullable', 'array'],
            'channels.*' => ['string', 'in:email,whatsapp,sms'],
            'is_enabled' => ['nullable', 'boolean'],
            'send_to_contact_person' => ['nullable', 'boolean'],
            'escalation_email' => ['nullable', 'email', 'max:255'],
        ]);

        $settings = ReminderSetting::forCurrentTenant();
        $settings->update($data);

        return response()->json(['data' => $settings->refresh()]);
    }

    /**
     * List reminder history.
     */
    public function history(Request $request): JsonResponse
    {
        $data = $this->reminderService->listHistory([
            'client_id' => $request->query('client_id'),
            'milestone' => $request->query('milestone'),
            'status' => $request->query('status'),
            'from' => $request->query('from'),
            'to' => $request->query('to'),
            'per_page' => min((int) ($request->query('per_page', 20)), 100),
        ]);

        return response()->json($data);
    }

    /**
     * Get reminder history for a specific invoice.
     */
    public function invoiceHistory(int $invoiceId): JsonResponse
    {
        return response()->json([
            'data' => $this->reminderService->historyForInvoice($invoiceId),
        ]);
    }

    /**
     * Manually trigger aging reminders for current tenant.
     */
    public function trigger(): JsonResponse
    {
        $result = $this->reminderService->processForTenant((int) app('tenant.id'));

        return response()->json([
            'data' => $result,
            'message' => "Sent: {$result['sent']}, Skipped: {$result['skipped']}",
        ]);
    }
}
