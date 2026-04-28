<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Banking\Models\BankAccount;
use App\Domain\Banking\Services\BankAccountService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Banking\StoreBankAccountRequest;
use App\Http\Requests\Banking\UpdateBankAccountRequest;
use App\Http\Resources\BankAccountResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class BankAccountController extends Controller
{
    public function __construct(
        private readonly BankAccountService $service,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $bankAccounts = $this->service->list([
            'search' => $request->query('search'),
            'is_active' => $request->has('is_active')
                ? filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
                : null,
            'currency' => $request->query('currency'),
            'sort_by' => $request->query('sort_by'),
            'sort_dir' => $request->query('sort_dir', 'desc'),
            'per_page' => min((int) ($request->query('per_page', 25)), 100),
        ]);

        return BankAccountResource::collection($bankAccounts);
    }

    public function store(StoreBankAccountRequest $request): JsonResponse
    {
        $bankAccount = $this->service->create($request->validated());

        return (new BankAccountResource($bankAccount))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(BankAccount $bankAccount): BankAccountResource
    {
        return new BankAccountResource($bankAccount->load('glAccount:id,code,name_ar,name_en'));
    }

    public function update(UpdateBankAccountRequest $request, BankAccount $bankAccount): BankAccountResource
    {
        $bankAccount = $this->service->update($bankAccount, $request->validated());

        return new BankAccountResource($bankAccount);
    }

    public function destroy(BankAccount $bankAccount): JsonResponse
    {
        $this->service->delete($bankAccount);

        return response()->json([
            'message' => __('messages.success.deleted'),
        ]);
    }
}
