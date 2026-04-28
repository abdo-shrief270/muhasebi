<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Client\Models\Client;
use App\Domain\Client\Models\ClientProduct;
use App\Domain\Client\Services\ClientProductService;
use App\Http\Controllers\Controller;
use App\Http\Requests\ClientProduct\StoreClientProductRequest;
use App\Http\Requests\ClientProduct\UpdateClientProductRequest;
use App\Http\Resources\ClientProductResource;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-client billable items + tenant-wide catalog rollup.
 *
 * Two route shapes:
 *   - /clients/{client}/products       — per-client CRUD (the client detail
 *                                        page's Products tab + invoice line picker source).
 *   - /catalog                         — read-only flattened list across all
 *                                        clients in the tenant (the catalog page).
 *
 * Tenant scoping is automatic via BelongsToTenant on the model. Route-model
 * binding ensures the {client} and {product} params already belong to the
 * current tenant; trying to access another tenant's row 404s.
 */
class ClientProductController extends Controller
{
    public function __construct(
        private readonly ClientProductService $service,
    ) {}

    /** Per-client list — bound to /clients/{client}/products. */
    public function index(Request $request, Client $client): AnonymousResourceCollection
    {
        $products = $this->service->listForClient($client, [
            'search' => $request->query('search'),
            'is_active' => $request->has('is_active')
                ? filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
                : null,
            'sort_by' => $request->query('sort_by', 'name'),
            'sort_dir' => $request->query('sort_dir', 'asc'),
            'per_page' => (int) ($request->query('per_page', 25)),
        ]);

        return ClientProductResource::collection($products);
    }

    public function store(StoreClientProductRequest $request, Client $client): ClientProductResource
    {
        try {
            $product = $this->service->create($client, $request->validated());
        } catch (UniqueConstraintViolationException) {
            // Hits the unique(tenant_id, client_id, name) index — surface as
            // a 422 instead of bubbling the SQL error out. Laravel's typed
            // wrapper covers both MySQL (SQLSTATE 23000) and PostgreSQL
            // (SQLSTATE 23505), so we don't need to inspect the code.
            throw ValidationException::withMessages([
                'name' => [__('messages.error.duplicate_name')],
            ]);
        }

        return new ClientProductResource($product);
    }

    public function show(Client $client, ClientProduct $product): ClientProductResource
    {
        $this->ensureBelongsToClient($client, $product);

        return new ClientProductResource($product->load('defaultAccount:id,code,name_ar,name_en'));
    }

    public function update(
        UpdateClientProductRequest $request,
        Client $client,
        ClientProduct $product,
    ): ClientProductResource {
        $this->ensureBelongsToClient($client, $product);

        $this->service->update($product, $request->validated());

        return new ClientProductResource($product);
    }

    public function destroy(Client $client, ClientProduct $product): JsonResponse
    {
        $this->ensureBelongsToClient($client, $product);
        $this->service->delete($product);

        return response()->json(['message' => __('messages.success.deleted')]);
    }

    /**
     * Tenant-wide catalog — flattens products across all clients. Read-only;
     * mutations always go through the per-client routes so the audit trail
     * and route-model binding stay sensible.
     */
    public function catalog(Request $request): AnonymousResourceCollection
    {
        $products = $this->service->listCatalog([
            'search' => $request->query('search'),
            'client_id' => $request->query('client_id'),
            'is_active' => $request->has('is_active')
                ? filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
                : null,
            'sort_by' => $request->query('sort_by', 'name'),
            'sort_dir' => $request->query('sort_dir', 'asc'),
            'per_page' => (int) ($request->query('per_page', 25)),
        ]);

        return ClientProductResource::collection($products);
    }

    /**
     * Defense-in-depth: route-model binding will already 404 if the product
     * doesn't exist in the current tenant, but if a caller passes a
     * mismatched (client, product) pair we still want a clean 404 — not a
     * silent leak of a different client's product.
     */
    private function ensureBelongsToClient(Client $client, ClientProduct $product): void
    {
        if ($product->client_id !== $client->id) {
            abort(Response::HTTP_NOT_FOUND);
        }
    }
}
