<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Accounting\Enums\RecurringFrequency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class RecurringJournalEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isUpdate = $this->isMethod('PUT') || $this->isMethod('PATCH');
        $sometimes = $isUpdate ? 'sometimes' : 'required';

        return [
            'template_name_ar' => [$sometimes, 'string', 'max:255'],
            'template_name_en' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'frequency' => [$sometimes, Rule::enum(RecurringFrequency::class)],
            'lines' => [$sometimes, 'array', 'min:2'],
            'lines.*.account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where('tenant_id', app('tenant.id'))],
            'lines.*.debit' => ['required', 'numeric', 'min:0'],
            'lines.*.credit' => ['required', 'numeric', 'min:0'],
            'lines.*.description' => ['nullable', 'string', 'max:500'],
            'lines.*.cost_center' => ['nullable', 'string', 'max:255'],
            'next_run_date' => [$sometimes, 'date', 'after_or_equal:today'],
            'end_date' => ['nullable', 'date', 'after:next_run_date'],
            'is_active' => ['boolean'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $lines = $this->input('lines');

                if (! is_array($lines) || empty($lines)) {
                    return;
                }

                $totalDebit = '0.00';
                $totalCredit = '0.00';

                foreach ($lines as $index => $line) {
                    $debit = (string) ($line['debit'] ?? '0');
                    $credit = (string) ($line['credit'] ?? '0');

                    // A line must have either debit or credit, not both
                    if (bccomp($debit, '0', 2) > 0 && bccomp($credit, '0', 2) > 0) {
                        $validator->errors()->add(
                            "lines.{$index}",
                            'A line cannot have both debit and credit amounts.'
                        );
                    }

                    if (bccomp($debit, '0', 2) <= 0 && bccomp($credit, '0', 2) <= 0) {
                        $validator->errors()->add(
                            "lines.{$index}",
                            'A line must have either a debit or credit amount greater than zero.'
                        );
                    }

                    $totalDebit = bcadd($totalDebit, $debit, 2);
                    $totalCredit = bcadd($totalCredit, $credit, 2);
                }

                if (bccomp($totalDebit, $totalCredit, 2) !== 0) {
                    $validator->errors()->add(
                        'lines',
                        "Total debits ({$totalDebit}) must equal total credits ({$totalCredit})."
                    );
                }
            },
        ];
    }
}
