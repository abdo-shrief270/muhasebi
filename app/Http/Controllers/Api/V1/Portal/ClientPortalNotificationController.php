<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Portal;

use App\Domain\Notification\Services\NotificationService;
use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ClientPortalNotificationController extends Controller
{
    public function __construct(
        private readonly NotificationService $notificationService,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return NotificationResource::collection(
            $this->notificationService->list([
                'type' => $request->query('type'),
                'is_read' => $request->query('is_read') !== null
                    ? filter_var($request->query('is_read'), FILTER_VALIDATE_BOOLEAN)
                    : null,
                'per_page' => min((int) ($request->query('per_page', 15)), 100),
            ]),
        );
    }

    public function markAsRead(string $notification): NotificationResource
    {
        return new NotificationResource(
            $this->notificationService->markAsRead($notification),
        );
    }

    public function markAllAsRead(): JsonResponse
    {
        $count = $this->notificationService->markAllAsRead();

        return response()->json(['count' => $count]);
    }
}
