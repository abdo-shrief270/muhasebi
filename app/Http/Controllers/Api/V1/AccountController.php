<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Services\AccountService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Account\StoreAccountRequest;
use App\Http\Requests\Account\UpdateAccountRequest;
use App\Http\Resources\AccountResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class AccountController extends Controller
{
    public function __construct(
        private readonly AccountService $accountService,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $accounts = $this->accountService->list([
            'search' => $request->query('search'),
            'type' => $request->query('type'),
            'is_active' => $request->has('is_active') ? filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN) : null,
            'is_group' => $request->has('is_group') ? filter_var($request->query('is_group'), FILTER_VALIDATE_BOOLEAN) : null,
            'parent_id' => $request->query('parent_id'),
            'sort_by' => $request->query('sort_by', 'code'),
            'sort_dir' => $request->query('sort_dir', 'asc'),
            'per_page' => min((int) ($request->query('per_page', 15)), 100),
        ]);

        return AccountResource::collection($accounts);
    }

    public function tree(): JsonResponse
    {
        $tree = $this->accountService->getTree();

        return response()->json([
            'data' => AccountResource::collection($tree),
        ]);
    }

    public function store(StoreAccountRequest $request): JsonResponse
    {
        $account = $this->accountService->create($request->validated());

        return (new AccountResource($account))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Account $account): AccountResource
    {
        return new AccountResource($this->accountService->show($account));
    }

    public function update(UpdateAccountRequest $request, Account $account): AccountResource
    {
        $account = $this->accountService->update($account, $request->validated());

        return new AccountResource($account);
    }

    public function destroy(Account $account): JsonResponse
    {
        $this->accountService->delete($account);

        return response()->json([
            'message' => 'Account deleted successfully.',
        ]);
    }
}
