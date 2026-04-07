<?php

declare(strict_types=1);

namespace App\Domain\ClientPortal\Services;

use App\Domain\Client\Models\Client;
use App\Domain\ClientPortal\Enums\MessageDirection;
use App\Domain\ClientPortal\Models\Message;
use App\Domain\Notification\Enums\NotificationChannel;
use App\Domain\Notification\Enums\NotificationType;
use App\Domain\Notification\Services\NotificationService;
use App\Domain\Shared\Enums\UserRole;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;

class MessageService
{
    public function __construct(
        private readonly NotificationService $notificationService,
    ) {}

    /**
     * List messages for a client (portal view).
     *
     * @param  array<string, mixed>  $filters
     */
    public function listForClient(Client $client, array $filters = []): LengthAwarePaginator
    {
        return Message::query()
            ->forClient($client->id)
            ->with('sender')
            ->orderByDesc('created_at')
            ->paginate($filters['per_page'] ?? 15);
    }

    /**
     * List messages for a client (firm view — same data, different context).
     *
     * @param  array<string, mixed>  $filters
     */
    public function listForFirm(Client $client, array $filters = []): LengthAwarePaginator
    {
        return $this->listForClient($client, $filters);
    }

    /**
     * Send a message from the client to the firm.
     *
     * @param  array<string, mixed>  $data
     */
    public function sendFromClient(Client $client, User $sender, array $data): Message
    {
        $message = Message::query()->create([
            'tenant_id' => (int) app('tenant.id'),
            'client_id' => $client->id,
            'user_id' => $sender->id,
            'direction' => MessageDirection::Inbound,
            'subject' => $data['subject'],
            'body' => $data['body'],
        ]);

        // Notify firm admin(s)
        $admins = User::query()
            ->where('role', UserRole::Admin)
            ->where('is_active', true)
            ->get();

        foreach ($admins as $admin) {
            $this->notificationService->send(
                userId: $admin->id,
                type: NotificationType::NewMessage,
                titleAr: "رسالة جديدة من {$client->name}",
                titleEn: "New message from {$client->name}",
                bodyAr: $data['subject'],
                actionUrl: "/clients/{$client->id}/messages",
                channel: NotificationChannel::InApp,
            );
        }

        return $message->load('sender');
    }

    /**
     * Send a message from the firm to the client.
     *
     * @param  array<string, mixed>  $data
     */
    public function sendFromFirm(Client $client, User $sender, array $data): Message
    {
        $message = Message::query()->create([
            'tenant_id' => (int) app('tenant.id'),
            'client_id' => $client->id,
            'user_id' => $sender->id,
            'direction' => MessageDirection::Outbound,
            'subject' => $data['subject'],
            'body' => $data['body'],
        ]);

        // Notify client portal user(s)
        $portalUsers = $client->portalUsers()->where('is_active', true)->get();

        foreach ($portalUsers as $portalUser) {
            $this->notificationService->send(
                userId: $portalUser->id,
                type: NotificationType::NewMessage,
                titleAr: 'رسالة جديدة من مكتب المحاسبة',
                titleEn: 'New message from your accounting firm',
                bodyAr: $data['subject'],
                actionUrl: '/portal/messages',
                channel: NotificationChannel::Both,
            );
        }

        return $message->load('sender');
    }

    /**
     * Show a message.
     */
    public function show(Message $message): Message
    {
        return $message->load('sender');
    }

    /**
     * Mark a message as read.
     */
    public function markAsRead(Message $message): Message
    {
        $message->markAsRead();

        return $message->refresh();
    }
}
