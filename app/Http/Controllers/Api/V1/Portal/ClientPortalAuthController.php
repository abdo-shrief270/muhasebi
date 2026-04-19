<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Portal;

use App\Domain\ClientPortal\Services\ClientInvitationService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientPortalAuthController extends Controller
{
    public function __construct(
        private readonly ClientInvitationService $invitationService,
    ) {}

    /**
     * Exchange a single-use invite token for a portal Sanctum token.
     * Sets the user's password in the same step, so the client never has
     * to remember the random value we generated at invite time.
     */
    public function acceptInvite(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $result = $this->invitationService->acceptInvite($data['token'], $data['password']);

        return response()->json([
            'message' => 'Invite accepted.',
            'data' => [
                'user' => [
                    'id' => $result['user']->id,
                    'name' => $result['user']->name,
                    'email' => $result['user']->email,
                    'role' => $result['user']->role->value,
                    'tenant_id' => $result['user']->tenant_id,
                    'client_id' => $result['user']->client_id,
                ],
                'token' => $result['token'],
            ],
        ]);
    }
}
