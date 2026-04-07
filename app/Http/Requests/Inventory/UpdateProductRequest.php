<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use App\Domain\Inventory\Enums\ValuationMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && app()->bound('tenant.id');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $tenantId = app('tenant.id');
        $productId = $this->route('product')?->id ?? $this->route('product');

        return [
            'category_id' => ['nullable', 'integer', Rule::exists('product_categories', 'id')->where('tenant_id', $tenantId)],
            'sku' => ['sometimes', 'string', 'max:30', Rule::unique('products')->where('tenant_id', $tenantId)->ignore($productId)],
            'name_ar' => ['sometimes', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'unit_of_measure' => ['nullable', 'string', 'max:20'],
            'purchase_price' => ['nullable', 'numeric', 'min:0'],
            'selling_price' => ['nullable', 'numeric', 'min:0'],
            'vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'reorder_level' => ['nullable', 'integer', 'min:0'],
            'valuation_method' => ['nullable', 'string', Rule::in(array_column(ValuationMethod::cases(), 'value'))],
            'is_active' => ['nullable', 'boolean'],
            'account_id' => ['nullable', 'integer', Rule::exists('accounts', 'id')->where('tenant_id', $tenantId)],
            'cogs_account_id' => ['nullable', 'integer', Rule::exists('accounts', 'id')->where('tenant_id', $tenantId)],
            'revenue_account_id' => ['nullable', 'integer', Rule::exists('accounts', 'id')->where('tenant_id', $tenantId)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'sku.unique' => 'رمز المنتج مستخدم بالفعل.',
            'sku.max' => 'رمز المنتج يجب ألا يتجاوز 30 حرف.',
            'name_ar.string' => 'اسم المنتج بالعربية يجب أن يكون نصاً.',
            'category_id.exists' => 'الفئة المحددة غير موجودة.',
            'purchase_price.min' => 'سعر الشراء يجب أن يكون صفراً أو أكثر.',
            'selling_price.min' => 'سعر البيع يجب أن يكون صفراً أو أكثر.',
        ];
    }
}
