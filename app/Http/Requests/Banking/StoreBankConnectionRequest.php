<?php

declare(strict_types=1);

namespace App\Http\Requests\Banking;

use App\Domain\Banking\Enums\BankCode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBankConnectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && app()->bound('tenant.id');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'bank_code' => ['required', 'string', 'max:20', Rule::in(array_column(BankCode::cases(), 'value'))],
            'account_number' => [
                'required',
                'string',
                'max:50',
                Rule::unique('bank_connections')
                    ->where('tenant_id', app('tenant.id'))
                    ->where('bank_code', $this->input('bank_code'))
                    ->ignore($this->route('bankConnection')),
            ],
            'iban' => ['nullable', 'string', 'max:34'],
            'account_name' => ['nullable', 'string', 'max:255'],
            'currency' => ['nullable', 'string', 'size:3'],
            'connection_type' => ['nullable', 'string', 'in:manual,api,file_import'],
            'api_credentials' => ['nullable', 'array'],
            'balance' => ['nullable', 'numeric'],
            'balance_date' => ['nullable', 'date'],
            'is_active' => ['nullable', 'boolean'],
            'linked_gl_account_id' => [
                'nullable',
                'integer',
                Rule::exists('accounts', 'id')->where('tenant_id', app('tenant.id')),
            ],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'bank_code.required' => 'كود البنك مطلوب.',
            'bank_code.in' => 'كود البنك غير صالح.',
            'account_number.required' => 'رقم الحساب مطلوب.',
            'account_number.unique' => 'رقم الحساب مسجل بالفعل لهذا البنك.',
            'account_number.max' => 'رقم الحساب يجب ألا يتجاوز 50 حرف.',
            'iban.max' => 'رقم الآيبان يجب ألا يتجاوز 34 حرف.',
            'currency.size' => 'رمز العملة يجب أن يكون 3 أحرف.',
            'connection_type.in' => 'نوع الاتصال غير صالح.',
            'linked_gl_account_id.exists' => 'الحساب المحاسبي المحدد غير موجود.',
            'notes.max' => 'الملاحظات يجب ألا تتجاوز 2000 حرف.',
        ];
    }
}
