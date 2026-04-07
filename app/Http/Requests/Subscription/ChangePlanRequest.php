<?php

declare(strict_types=1);

namespace App\Http\Requests\Subscription;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangePlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && app()->bound('tenant.id');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'plan_id' => ['required', 'integer', Rule::exists('plans', 'id')],
            'billing_cycle' => ['nullable', 'string', Rule::in(['monthly', 'annual'])],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'plan_id.required' => 'يجب اختيار الخطة الجديدة.',
            'plan_id.exists' => 'الخطة المحددة غير موجودة.',
            'billing_cycle.in' => 'دورة الفوترة يجب أن تكون شهرية أو سنوية.',
        ];
    }
}
