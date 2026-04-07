<?php

declare(strict_types=1);

namespace App\Http\Requests\JournalEntry;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreJournalEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && app()->bound('tenant.id');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date'],
            'description' => ['required', 'string', 'max:1000'],
            'reference' => ['nullable', 'string', 'max:100'],
            'fiscal_period_id' => ['nullable', 'integer', Rule::exists('fiscal_periods', 'id')->where('tenant_id', app('tenant.id'))],
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where('tenant_id', app('tenant.id'))],
            'lines.*.debit' => ['required', 'numeric', 'min:0'],
            'lines.*.credit' => ['required', 'numeric', 'min:0'],
            'lines.*.description' => ['nullable', 'string', 'max:500'],
            'lines.*.cost_center' => ['nullable', 'string', 'max:50'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'date.required' => 'تاريخ القيد مطلوب.',
            'description.required' => 'وصف القيد مطلوب.',
            'lines.required' => 'سطور القيد مطلوبة.',
            'lines.min' => 'يجب أن يحتوي القيد على سطرين على الأقل.',
            'lines.*.account_id.required' => 'الحساب مطلوب لكل سطر.',
            'lines.*.account_id.exists' => 'الحساب المحدد غير موجود.',
            'lines.*.debit.required' => 'المبلغ المدين مطلوب.',
            'lines.*.debit.min' => 'المبلغ المدين يجب أن يكون صفر أو أكثر.',
            'lines.*.credit.required' => 'المبلغ الدائن مطلوب.',
            'lines.*.credit.min' => 'المبلغ الدائن يجب أن يكون صفر أو أكثر.',
            'fiscal_period_id.exists' => 'الفترة المحاسبية غير موجودة.',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $lines = $this->input('lines', []);

                if (! is_array($lines) || count($lines) < 2) {
                    return;
                }

                $totalDebit = 0;
                $totalCredit = 0;

                foreach ($lines as $index => $line) {
                    $debit = (float) ($line['debit'] ?? 0);
                    $credit = (float) ($line['credit'] ?? 0);

                    if ($debit > 0 && $credit > 0) {
                        $validator->errors()->add(
                            "lines.{$index}",
                            'لا يمكن أن يكون المبلغ المدين والدائن أكبر من صفر في نفس السطر.',
                        );
                    }

                    if ($debit == 0 && $credit == 0) {
                        $validator->errors()->add(
                            "lines.{$index}",
                            'يجب إدخال مبلغ مدين أو دائن في كل سطر.',
                        );
                    }

                    $totalDebit += $debit;
                    $totalCredit += $credit;
                }

                if (round($totalDebit, 2) !== round($totalCredit, 2)) {
                    $validator->errors()->add(
                        'lines',
                        'مجموع المبالغ المدينة يجب أن يساوي مجموع المبالغ الدائنة.',
                    );
                }
            },
        ];
    }
}
