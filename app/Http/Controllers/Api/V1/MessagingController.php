<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Integration\Services\BeonChatService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tenant-facing messaging controller.
 * Sends notifications to clients via WhatsApp/SMS using Beon.chat.
 */
class MessagingController extends Controller
{
    /**
     * Send a WhatsApp message to a client.
     */
    public function sendWhatsApp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => 'required|string|max:20',
            'message' => 'required|string|max:4096',
            'template_name' => 'nullable|string|max:100',
            'template_params' => 'nullable|array',
        ]);

        if (! BeonChatService::isConfigured()) {
            return response()->json([
                'error' => 'not_configured',
                'message' => 'WhatsApp messaging is not configured. Contact the administrator.',
            ], 422);
        }

        $result = BeonChatService::sendWhatsApp(
            phone: $data['phone'],
            message: $data['message'],
            options: array_filter([
                'template_name' => $data['template_name'] ?? null,
                'template_params' => $data['template_params'] ?? null,
            ]),
        );

        if ($result['success']) {
            return response()->json([
                'message' => 'WhatsApp message sent.',
                'data' => ['message_id' => $result['message_id'] ?? null],
            ]);
        }

        return response()->json([
            'error' => 'send_failed',
            'message' => $result['error'] ?? 'Failed to send message.',
        ], 422);
    }

    /**
     * Send an SMS message to a client.
     */
    public function sendSms(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => 'required|string|max:20',
            'message' => 'required|string|max:160',
        ]);

        if (! BeonChatService::isConfigured()) {
            return response()->json([
                'error' => 'not_configured',
                'message' => 'SMS messaging is not configured.',
            ], 422);
        }

        $result = BeonChatService::sendSms($data['phone'], $data['message']);

        if ($result['success']) {
            return response()->json(['message' => 'SMS sent.']);
        }

        return response()->json([
            'error' => 'send_failed',
            'message' => $result['error'] ?? 'Failed to send SMS.',
        ], 422);
    }

    /**
     * List WhatsApp templates available for sending.
     */
    public function templates(): JsonResponse
    {
        return response()->json([
            'data' => BeonChatService::listTemplates(),
        ]);
    }

    /**
     * List conversations from Beon.chat inbox.
     */
    public function conversations(Request $request): JsonResponse
    {
        return response()->json(
            BeonChatService::listConversations(array_merge($request->only('status', 'channel', 'page'), ['per_page' => min((int) ($request->query('per_page', 15)), 100)]))
        );
    }

    /**
     * Get messages in a conversation.
     */
    public function conversationMessages(string $conversationId): JsonResponse
    {
        return response()->json(
            BeonChatService::getConversation($conversationId)
        );
    }

    /**
     * Reply to a conversation.
     */
    public function reply(Request $request, string $conversationId): JsonResponse
    {
        $request->validate(['message' => 'required|string|max:4096']);

        $result = BeonChatService::replyToConversation($conversationId, $request->input('message'));

        return response()->json($result, $result['success'] ? 200 : 422);
    }
}
