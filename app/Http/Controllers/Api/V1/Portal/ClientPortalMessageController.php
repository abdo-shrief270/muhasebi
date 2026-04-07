<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Portal;

use App\Domain\ClientPortal\Models\Message;
use App\Domain\ClientPortal\Services\MessageService;
use App\Http\Controllers\Controller;
use App\Http\Requests\ClientPortal\StoreMessageRequest;
use App\Http\Resources\MessageResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ClientPortalMessageController extends Controller
{
    public function __construct(
        private readonly MessageService $messageService,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return MessageResource::collection(
            $this->messageService->listForClient(app('portal.client'), [
                'per_page' => min((int) ($request->query('per_page', 15)), 100),
            ]),
        );
    }

    public function store(StoreMessageRequest $request): MessageResource
    {
        return new MessageResource(
            $this->messageService->sendFromClient(
                app('portal.client'),
                auth()->user(),
                $request->validated(),
            ),
        );
    }

    public function show(Message $message): MessageResource
    {
        abort_unless($message->client_id === app('portal.client')->id, 403);

        return new MessageResource(
            $this->messageService->show($message),
        );
    }

    public function markAsRead(Message $message): MessageResource
    {
        abort_unless($message->client_id === app('portal.client')->id, 403);

        return new MessageResource(
            $this->messageService->markAsRead($message),
        );
    }
}
