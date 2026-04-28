<?php

declare(strict_types=1);

namespace App\Http\Requests\Invoice;

use App\Domain\Billing\Enums\InvoiceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && app()->bound('tenant.id');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'client_id' => ['required', 'integer', Rule::exists('clients', 'id')->where('tenant_id', app('tenant.id'))],
            'date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:date'],
            'type' => ['nullable', 'string', Rule::in(array_column(InvoiceType::cases(), 'value'))],
            'notes' => ['nullable', 'string', 'max:2000'],
            'terms' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.description' => ['required', 'string', 'max:500'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.01'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'lines.*.discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'lines.*.vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'lines.*.account_id' => ['nullable', 'integer', Rule::exists('accounts', 'id')->where('tenant_id', app('tenant.id'))],
            // Optional FK back to a per-client product the line was sourced
            // from. The picker on the SPA sets this; freeform lines leave it
            // null. Tenant scoping is enforced via the where() clause; the
            // global BelongsToTenant scope is bypassed by exists() rules.
            'lines.*.client_product_id' => ['nullable', 'integer', Rule::exists('client_products', 'id')->where('tenant_id', app('tenant.id'))],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'client_id.required' => 'العميل مطلوب.',
            'client_id.exists' => 'العميل المحدد غير موجود.',
            'date.required' => 'تاريخ الفاتورة مطلوب.',
            'date.date' => 'تاريخ الفاتورة غير صالح.',
            'due_date.date' => 'تاريخ الاستحقاق غير صالح.',
            'due_date.after_or_equal' => 'تاريخ الاستحقاق يجب أن يكون بعد أو يساوي تاريخ الفاتورة.',
            'type.in' => 'نوع الفاتورة غير صالح.',
            'notes.max' => 'الملاحظات يجب ألا تتجاوز 2000 حرف.',
            'terms.max' => 'الشروط يجب ألا تتجاوز 2000 حرف.',
            'lines.required' => 'يجب إضافة بند واحد على الأقل.',
            'lines.min' => 'يجب إضافة بند واحد على الأقل.',
            'lines.*.description.required' => 'وصف البند مطلوب.',
            'lines.*.description.max' => 'وصف البند يجب ألا يتجاوز 500 حرف.',
            'lines.*.quantity.required' => 'الكمية مطلوبة.',
            'lines.*.quantity.numeric' => 'الكمية يجب أن تكون رقماً.',
            'lines.*.quantity.min' => 'الكمية يجب أن تكون أكبر من صفر.',
            'lines.*.unit_price.required' => 'سعر الوحدة مطلوب.',
            'lines.*.unit_price.numeric' => 'سعر الوحدة يجب أن يكون رقماً.',
            'lines.*.unit_price.min' => 'سعر الوحدة يجب أن يكون صفراً أو أكثر.',
            'lines.*.discount_percent.numeric' => 'نسبة الخصم يجب أن تكون رقماً.',
            'lines.*.discount_percent.min' => 'نسبة الخصم يجب أن تكون صفراً أو أكثر.',
            'lines.*.discount_percent.max' => 'نسبة الخصم يجب ألا تتجاوز 100%.',
            'lines.*.vat_rate.numeric' => 'نسبة الضريبة يجب أن تكون رقماً.',
            'lines.*.vat_rate.min' => 'نسبة الضريبة يجب أن تكون صفراً أو أكثر.',
            'lines.*.vat_rate.max' => 'نسبة الضريبة يجب ألا تتجاوز 100%.',
            'lines.*.account_id.exists' => 'الحساب المحدد غير موجود.',
        ];
    }
}
