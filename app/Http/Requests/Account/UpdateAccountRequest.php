<?php

declare(strict_types=1);

namespace App\Http\Requests\Account;

use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Enums\NormalBalance;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && app()->bound('tenant.id');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $tenantId = app('tenant.id');
        $accountId = $this->route('account')?->id;

        return [
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:20',
                Rule::unique('accounts')->where('tenant_id', $tenantId)->ignore($accountId),
            ],
            'name_ar' => ['sometimes', 'required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'type' => ['sometimes', 'required', 'string', Rule::in(array_column(AccountType::cases(), 'value'))],
            'normal_balance' => ['sometimes', 'required', 'string', Rule::in(array_column(NormalBalance::cases(), 'value'))],
            'parent_id' => ['nullable', 'integer', Rule::exists('accounts', 'id')],
            'is_group' => ['nullable', 'boolean'],
            'description' => ['nullable', 'string', 'max:1000'],
            'currency' => ['nullable', 'string', 'max:3'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'code.required' => 'رقم الحساب مطلوب.',
            'code.unique' => 'رقم الحساب مسجل بالفعل.',
            'name_ar.required' => 'اسم الحساب بالعربية مطلوب.',
            'type.in' => 'نوع الحساب غير صالح.',
            'normal_balance.in' => 'طبيعة الرصيد غير صالحة.',
            'parent_id.exists' => 'الحساب الأب غير موجود.',
        ];
    }
}
