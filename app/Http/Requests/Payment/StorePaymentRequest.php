<?php

declare(strict_types=1);

namespace App\Http\Requests\Payment;

use App\Domain\Billing\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && app()->bound('tenant.id');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'invoice_id' => ['required', 'integer', Rule::exists('invoices', 'id')->where('tenant_id', app('tenant.id'))],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:9999999999.99'],
            'date' => ['required', 'date'],
            'method' => ['required', 'string', Rule::in(array_column(PaymentMethod::cases(), 'value'))],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'invoice_id.required' => 'الفاتورة مطلوبة.',
            'invoice_id.exists' => 'الفاتورة المحددة غير موجودة.',
            'amount.required' => 'المبلغ مطلوب.',
            'amount.numeric' => 'المبلغ يجب أن يكون رقماً.',
            'amount.min' => 'المبلغ يجب أن يكون أكبر من صفر.',
            'date.required' => 'تاريخ الدفع مطلوب.',
            'date.date' => 'تاريخ الدفع غير صالح.',
            'method.required' => 'طريقة الدفع مطلوبة.',
            'method.in' => 'طريقة الدفع غير صالحة.',
            'reference.max' => 'المرجع يجب ألا يتجاوز 100 حرف.',
            'notes.max' => 'الملاحظات يجب ألا تتجاوز 2000 حرف.',
        ];
    }
}
