<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use App\Domain\Inventory\Enums\MovementType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordMovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && app()->bound('tenant.id');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $tenantId = app('tenant.id');

        return [
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('tenant_id', $tenantId)],
            'movement_type' => ['required', 'string', Rule::in(array_column(MovementType::cases(), 'value'))],
            'quantity' => ['required', 'integer', 'min:1'],
            'unit_cost' => ['required_if:movement_type,purchase', 'nullable', 'numeric', 'min:0'],
            'reference_type' => ['nullable', 'string', 'max:50'],
            'reference_id' => ['nullable', 'integer'],
            'warehouse' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'product_id.required' => 'المنتج مطلوب.',
            'product_id.exists' => 'المنتج المحدد غير موجود.',
            'movement_type.required' => 'نوع الحركة مطلوب.',
            'movement_type.in' => 'نوع الحركة غير صالح.',
            'quantity.required' => 'الكمية مطلوبة.',
            'quantity.min' => 'الكمية يجب أن تكون 1 على الأقل.',
            'unit_cost.required_if' => 'تكلفة الوحدة مطلوبة عند الشراء.',
            'unit_cost.min' => 'تكلفة الوحدة يجب أن تكون صفراً أو أكثر.',
        ];
    }
}
