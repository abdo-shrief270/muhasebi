<?php

declare(strict_types=1);

namespace App\Domain\AccountsPayable\Services;

use App\Domain\AccountsPayable\Models\Vendor;
use App\Domain\AccountsPayable\Models\VendorProduct;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class VendorProductService
{
    /**
     * List products for a single vendor — used by the vendor detail page's
     * Products tab and as the bill-line picker source.
     *
     * @param  array<string, mixed>  $params
     */
    public function listForVendor(Vendor $vendor, array $params = []): LengthAwarePaginator
    {
        // Query the model directly (vs. the HasMany) so applyFilters() can
        // re-use the same Builder typehint as the catalog rollup if/when we
        // add one.
        $query = VendorProduct::query()
            ->with('defaultAccount:id,code,name_ar,name_en')
            ->where('vendor_id', $vendor->id);

        return $this->applyFilters($query, $params)->paginate(
            perPage: min((int) ($params['per_page'] ?? 25), 100),
        );
    }

    /**
     * Tenant-wide catalog list — flattens products across all vendors. Eager
     * loads `vendor` so the catalog UI can show the owning vendor name
     * without N+1, and `defaultAccount` so the row can display the GL code.
     *
     * @param  array<string, mixed>  $params
     */
    public function listCatalog(array $params = []): LengthAwarePaginator
    {
        $query = VendorProduct::query()
            ->with([
                'vendor:id,name_ar,name_en,code,currency',
                'defaultAccount:id,code,name_ar,name_en',
            ]);

        if (! empty($params['vendor_id'])) {
            $query->where('vendor_id', (int) $params['vendor_id']);
        }

        return $this->applyFilters($query, $params)->paginate(
            perPage: min((int) ($params['per_page'] ?? 25), 100),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Vendor $vendor, array $data): VendorProduct
    {
        // Refresh after insert so DB defaults (`is_active = true` from the
        // migration) are reflected — without this the resource would
        // serialize a model with `is_active` unset, rendering as `false`.
        return $vendor->products()
            ->create($data + ['tenant_id' => app('tenant.id')])
            ->refresh()
            ->load('defaultAccount:id,code,name_ar,name_en');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(VendorProduct $product, array $data): VendorProduct
    {
        // Pre-flight the rename so the unique (tenant, vendor, name) index
        // doesn't surface as a generic 500. The controller still catches
        // UniqueConstraintViolationException as a defense-in-depth fallback
        // for race conditions between this check and the UPDATE.
        if (
            isset($data['name'])
            && $data['name'] !== $product->name
            && VendorProduct::query()
                ->where('vendor_id', $product->vendor_id)
                ->where('name', $data['name'])
                ->whereKeyNot($product->id)
                ->exists()
        ) {
            throw ValidationException::withMessages([
                'name' => [__('messages.error.duplicate_name')],
            ]);
        }

        $product->fill($data)->save();

        return $product->load('defaultAccount:id,code,name_ar,name_en');
    }

    public function delete(VendorProduct $product): void
    {
        $product->delete();
    }

    /**
     * Bump `last_used_at` whenever this product appears on a freshly-created
     * bill line. Called from a bill-line observer (no-op until the picker
     * is wired through bill creation).
     */
    public function touchLastUsed(VendorProduct $product): void
    {
        $product->forceFill(['last_used_at' => now()])->saveQuietly();
    }

    /**
     * @param  array<string, mixed>  $params
     * @return Builder<VendorProduct>
     */
    private function applyFilters(Builder $query, array $params): Builder
    {
        if (! empty($params['search'])) {
            $term = '%'.$params['search'].'%';
            $query->where(function (Builder $q) use ($term): void {
                $q->where('name', 'ilike', $term)
                  ->orWhere('description', 'ilike', $term);
            });
        }

        if (array_key_exists('is_active', $params) && $params['is_active'] !== null) {
            $query->where('is_active', (bool) $params['is_active']);
        }

        $sortBy = in_array(
            $params['sort_by'] ?? null,
            ['name', 'unit_price', 'last_used_at', 'created_at'],
            true,
        ) ? $params['sort_by'] : 'name';

        $sortDir = ($params['sort_dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

        // When sorting by last_used_at descending, push NULLs last — Postgres
        // defaults to NULLS FIRST on DESC which would surface never-used
        // items at the top of the picker, the opposite of what we want.
        // Tie-break by name so equal timestamps stay alphabetical.
        if ($sortBy === 'last_used_at') {
            $query->orderByRaw('last_used_at '.$sortDir.' NULLS LAST')
                  ->orderBy('name');
        } else {
            $query->orderBy($sortBy, $sortDir);
        }

        return $query;
    }
}
