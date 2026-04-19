<?php

declare(strict_types=1);

namespace App\Domain\Currency\Services;

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\JournalEntryLine;
use App\Domain\Accounting\Services\JournalEntryService;
use App\Domain\Currency\Models\ExchangeRate;
use App\Domain\Currency\Models\FxRevaluation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FxRevaluationService
{
    public function __construct(
        private readonly JournalEntryService $journalEntryService,
    ) {}

    /**
     * Calculate FX revaluation for all foreign currency accounts as of given date.
     */
    public function calculate(string $date): FxRevaluation
    {
        return DB::transaction(function () use ($date): FxRevaluation {
            $functionalCurrency = 'EGP';

            // Find all accounts with foreign currency balances (non-EGP journal entry lines)
            $foreignAccounts = JournalEntryLine::query()
                ->select('account_id', 'currency')
                ->whereHas('journalEntry', fn ($q) => $q->where('status', 'posted')->where('date', '<=', $date))
                ->where('currency', '!=', $functionalCurrency)
                ->groupBy('account_id', 'currency')
                ->get();

            $revaluation = FxRevaluation::query()->create([
                'revaluation_date' => $date,
                'functional_currency' => $functionalCurrency,
                'status' => 'draft',
                'created_by' => Auth::id(),
            ]);

            $totalGain = '0.00';
            $totalLoss = '0.00';

            foreach ($foreignAccounts as $entry) {
                $accountId = $entry->account_id;
                $currency = $entry->currency;

                // Get foreign currency balance: sum(debit) - sum(credit) for this currency
                $balanceData = JournalEntryLine::query()
                    ->where('account_id', $accountId)
                    ->where('currency', $currency)
                    ->whereHas('journalEntry', fn ($q) => $q->where('status', 'posted')->where('date', '<=', $date))
                    ->selectRaw('COALESCE(SUM(debit), 0) as total_debit, COALESCE(SUM(credit), 0) as total_credit')
                    ->first();

                $foreignBalance = bcsub((string) $balanceData->total_debit, (string) $balanceData->total_credit, 2);

                // Skip zero balances
                if (bccomp($foreignBalance, '0.00', 2) === 0) {
                    continue;
                }

                // Get weighted average original rate from transactions
                $originalRate = $this->getWeightedAverageRate($accountId, $currency, $date);

                // Get new exchange rate as of revaluation date
                $newRate = ExchangeRate::getRate($currency, $functionalCurrency, $date);

                if ($originalRate === null || $newRate === null) {
                    continue;
                }

                $originalRateStr = bcadd((string) $originalRate, '0', 6);
                $newRateStr = bcadd((string) $newRate, '0', 6);

                // revalued_balance = foreign_balance * new_rate
                $revaluedBalance = bcmul($foreignBalance, $newRateStr, 2);

                // gain_loss = revalued_balance - (foreign_balance * original_rate)
                $originalFunctionalBalance = bcmul($foreignBalance, $originalRateStr, 2);
                $gainLoss = bcsub($revaluedBalance, $originalFunctionalBalance, 2);

                // Skip zero gain/loss
                if (bccomp($gainLoss, '0.00', 2) === 0) {
                    continue;
                }

                $revaluation->lines()->create([
                    'account_id' => $accountId,
                    'currency' => $currency,
                    'original_balance' => $foreignBalance,
                    'original_rate' => $originalRateStr,
                    'new_rate' => $newRateStr,
                    'revalued_balance' => $revaluedBalance,
                    'gain_loss' => $gainLoss,
                ]);

                if (bccomp($gainLoss, '0.00', 2) > 0) {
                    $totalGain = bcadd($totalGain, $gainLoss, 2);
                } else {
                    $totalLoss = bcadd($totalLoss, $gainLoss, 2);
                }
            }

            $netGainLoss = bcadd($totalGain, $totalLoss, 2);

            $revaluation->update([
                'total_gain' => $totalGain,
                'total_loss' => $totalLoss,
                'net_gain_loss' => $netGainLoss,
            ]);

            return $revaluation->load('lines.account');
        });
    }

    /**
     * Post the FX revaluation to the GL.
     */
    public function post(FxRevaluation $reval): FxRevaluation
    {
        if (! $reval->isDraft()) {
            throw ValidationException::withMessages([
                'status' => ['Only draft revaluations can be posted.'],
            ]);
        }

        return DB::transaction(function () use ($reval): FxRevaluation {
            $reval->load('lines');

            $fxGainCode = config('accounting.default_accounts.fx_gain', '7210');
            $fxLossCode = config('accounting.default_accounts.fx_loss', '6810');

            $fxGainAccount = Account::query()->where('code', $fxGainCode)->first();
            $fxLossAccount = Account::query()->where('code', $fxLossCode)->first();

            if (! $fxGainAccount || ! $fxLossAccount) {
                throw ValidationException::withMessages([
                    'accounts' => ['FX gain/loss accounts not found. Please configure account codes '.$fxGainCode.' and '.$fxLossCode.'.'],
                ]);
            }

            $journalLines = [];

            foreach ($reval->lines as $line) {
                $gainLoss = (string) $line->gain_loss;

                if (bccomp($gainLoss, '0.00', 2) > 0) {
                    // Gain: DEBIT the revalued account, CREDIT fx_gain account
                    $journalLines[] = [
                        'account_id' => $line->account_id,
                        'debit' => $gainLoss,
                        'credit' => '0.00',
                        'currency' => 'EGP',
                        'description' => 'FX revaluation gain — '.$line->currency,
                    ];
                    $journalLines[] = [
                        'account_id' => $fxGainAccount->id,
                        'debit' => '0.00',
                        'credit' => $gainLoss,
                        'currency' => 'EGP',
                        'description' => 'FX revaluation gain — '.$line->currency,
                    ];
                } elseif (bccomp($gainLoss, '0.00', 2) < 0) {
                    // Loss: DEBIT fx_loss account, CREDIT the revalued account
                    $absLoss = bcmul($gainLoss, '-1', 2);
                    $journalLines[] = [
                        'account_id' => $fxLossAccount->id,
                        'debit' => $absLoss,
                        'credit' => '0.00',
                        'currency' => 'EGP',
                        'description' => 'FX revaluation loss — '.$line->currency,
                    ];
                    $journalLines[] = [
                        'account_id' => $line->account_id,
                        'debit' => '0.00',
                        'credit' => $absLoss,
                        'currency' => 'EGP',
                        'description' => 'FX revaluation loss — '.$line->currency,
                    ];
                }
            }

            if (empty($journalLines)) {
                throw ValidationException::withMessages([
                    'lines' => ['No gain/loss lines to post.'],
                ]);
            }

            $journalEntry = $this->journalEntryService->create([
                'date' => $reval->revaluation_date->toDateString(),
                'description' => 'FX Revaluation — '.$reval->revaluation_date->toDateString(),
                'reference' => 'FX-REVAL-'.$reval->id,
                'lines' => $journalLines,
            ]);

            // Post the journal entry
            $this->journalEntryService->post($journalEntry);

            $reval->update([
                'status' => 'posted',
                'journal_entry_id' => $journalEntry->id,
            ]);

            return $reval->refresh()->load(['lines.account', 'journalEntry']);
        });
    }

    /**
     * Reverse a posted FX revaluation.
     */
    public function reverse(FxRevaluation $reval): FxRevaluation
    {
        if (! $reval->isPosted()) {
            throw ValidationException::withMessages([
                'status' => ['Only posted revaluations can be reversed.'],
            ]);
        }

        return DB::transaction(function () use ($reval): FxRevaluation {
            $reval->load('journalEntry');

            if ($reval->journalEntry) {
                $this->journalEntryService->reverse($reval->journalEntry);
            }

            $reval->update([
                'status' => 'draft',
                'journal_entry_id' => null,
            ]);

            return $reval->refresh()->load('lines.account');
        });
    }

    /**
     * List FX revaluations with filters and pagination.
     *
     * @param  array<string, mixed>  $filters
     */
    public function list(array $filters = []): LengthAwarePaginator
    {
        return FxRevaluation::query()
            ->with(['lines.account', 'createdByUser'])
            ->when(isset($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(isset($filters['date_from']), fn ($q) => $q->where('revaluation_date', '>=', $filters['date_from']))
            ->when(isset($filters['date_to']), fn ($q) => $q->where('revaluation_date', '<=', $filters['date_to']))
            ->orderBy($filters['sort_by'] ?? 'revaluation_date', $filters['sort_dir'] ?? 'desc')
            ->paginate($filters['per_page'] ?? 15);
    }

    /**
     * Get weighted average exchange rate for an account's foreign currency transactions.
     */
    private function getWeightedAverageRate(int $accountId, string $currency, string $date): ?float
    {
        // Calculate weighted average rate from posted journal entry lines
        // Weight by the absolute amount of each transaction
        $lines = JournalEntryLine::query()
            ->where('account_id', $accountId)
            ->where('currency', $currency)
            ->whereHas('journalEntry', fn ($q) => $q->where('status', 'posted')->where('date', '<=', $date))
            ->get();

        $totalAmount = '0.00';
        $weightedSum = '0.00';

        foreach ($lines as $line) {
            $amount = bcsub((string) $line->debit, (string) $line->credit, 2);
            $absAmount = bccomp($amount, '0', 2) >= 0 ? $amount : bcmul($amount, '-1', 2);

            if (bccomp($absAmount, '0.00', 2) === 0) {
                continue;
            }

            // Get the exchange rate at the time of the transaction
            $txDate = $line->journalEntry->date->toDateString();
            $rate = ExchangeRate::getRate($currency, 'EGP', $txDate);

            if ($rate === null) {
                continue;
            }

            $rateStr = bcadd((string) $rate, '0', 6);
            $weightedSum = bcadd($weightedSum, bcmul($absAmount, $rateStr, 6), 6);
            $totalAmount = bcadd($totalAmount, $absAmount, 2);
        }

        if (bccomp($totalAmount, '0.00', 2) === 0) {
            return null;
        }

        return (float) bcdiv($weightedSum, $totalAmount, 6);
    }
}
