<?php

declare(strict_types=1);

namespace App\Domain\Banking\Services;

use App\Domain\Accounting\Enums\ReconciliationStatus;
use App\Domain\Accounting\Models\BankReconciliation;
use App\Domain\Accounting\Models\BankStatementLine;
use App\Domain\Banking\Enums\BankCode;
use App\Domain\Banking\Models\BankConnection;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;

class BankConnectionService
{
    /**
     * List bank connections for the current tenant.
     *
     * @param  array<string, mixed>  $filters
     */
    public function list(array $filters = []): LengthAwarePaginator
    {
        return BankConnection::query()
            ->with('glAccount:id,code,name_ar,name_en')
            ->when(isset($filters['bank_code']), fn ($q) => $q->where('bank_code', $filters['bank_code']))
            ->when(isset($filters['is_active']), fn ($q) => $q->where('is_active', (bool) $filters['is_active']))
            ->orderBy('bank_code')
            ->orderBy('account_number')
            ->paginate($filters['per_page'] ?? 15);
    }

    /**
     * Create a new bank connection.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): BankConnection
    {
        $data['created_by'] = auth()->id();

        return BankConnection::create($data);
    }

    /**
     * Update an existing bank connection.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(BankConnection $connection, array $data): BankConnection
    {
        $connection->update($data);

        return $connection->refresh();
    }

    /**
     * Soft-delete a bank connection.
     */
    public function delete(BankConnection $connection): void
    {
        $connection->delete();
    }

    /**
     * Sync balance from the bank.
     * Placeholder — if API available, fetch balance. Otherwise return current stored balance.
     *
     * @return array{balance: string|null, balance_date: string|null, synced: bool}
     */
    public function syncBalance(BankConnection $connection): array
    {
        if ($connection->isApiEnabled()) {
            // Future: call bank API to fetch balance
            // For now, fall through to manual return
        }

        return [
            'balance' => $connection->balance,
            'balance_date' => $connection->balance_date?->toDateString(),
            'synced' => false,
        ];
    }

    /**
     * Import a bank statement file (CSV, OFX, MT940).
     * Creates a BankReconciliation and BankStatementLines.
     *
     * @return array{lines_imported: int, reconciliation_id: int}
     */
    public function importStatement(BankConnection $connection, UploadedFile $file, string $format): array
    {
        $lines = match ($format) {
            'csv' => $this->parseCsv($file),
            'ofx' => $this->parseOfx($file),
            'mt940' => $this->parseMt940($file),
            default => throw new \InvalidArgumentException("Unsupported format: {$format}"),
        };

        // Create a reconciliation linked to the GL account
        $reconciliation = BankReconciliation::create([
            'tenant_id' => $connection->tenant_id,
            'account_id' => $connection->linked_gl_account_id,
            'statement_date' => Carbon::today(),
            'statement_balance' => $connection->balance ?? 0,
            'status' => ReconciliationStatus::Draft->value,
            'notes' => "Imported from {$connection->bank_code->label()} — format: {$format}",
        ]);

        $imported = 0;
        foreach ($lines as $line) {
            BankStatementLine::create([
                'reconciliation_id' => $reconciliation->id,
                'date' => $line['date'],
                'description' => $line['description'] ?? null,
                'reference' => $line['reference'] ?? null,
                'amount' => $line['amount'],
                'type' => $line['amount'] >= 0 ? 'deposit' : 'withdrawal',
                'status' => 'unmatched',
            ]);
            $imported++;
        }

        return [
            'lines_imported' => $imported,
            'reconciliation_id' => $reconciliation->id,
        ];
    }

    /**
     * Generate a SWIFT MT103 or local bank transfer instruction.
     *
     * @param  array<string, mixed>  $data
     * @return array{format: string, content: string}
     */
    public function generatePaymentInstruction(array $data): array
    {
        $format = $data['format'] ?? 'mt103';

        if ($format === 'mt103') {
            return [
                'format' => 'mt103',
                'content' => $this->generateMt103($data),
            ];
        }

        return [
            'format' => 'local',
            'content' => $this->generateLocalTransfer($data),
        ];
    }

    /**
     * Return supported import formats for a bank.
     *
     * @return array<int, array{format: string, label: string}>
     */
    public function listSupportedFormats(string $bankCode): array
    {
        $formats = [
            ['format' => 'csv', 'label' => 'CSV (Comma-Separated Values)'],
        ];

        // Most Egyptian banks support OFX export
        if (in_array($bankCode, ['nbe', 'cib', 'hsbc', 'qnb', 'aaib'])) {
            $formats[] = ['format' => 'ofx', 'label' => 'OFX (Open Financial Exchange)'];
        }

        // International banks typically support MT940
        if (in_array($bankCode, ['hsbc', 'cib'])) {
            $formats[] = ['format' => 'mt940', 'label' => 'MT940 (SWIFT Statement)'];
        }

        return $formats;
    }

    /**
     * Dashboard: all connections with balances, sync status, last sync time.
     *
     * @return array<string, mixed>
     */
    public function dashboard(): array
    {
        $connections = BankConnection::query()
            ->with('glAccount:id,code,name_ar,name_en')
            ->orderBy('bank_code')
            ->get();

        $totalBalance = $connections->sum('balance');
        $activeCount = $connections->where('is_active', true)->count();
        $errorCount = $connections->where('sync_status', 'error')->count();

        return [
            'connections' => $connections,
            'summary' => [
                'total_connections' => $connections->count(),
                'active_connections' => $activeCount,
                'error_connections' => $errorCount,
                'total_balance' => number_format((float) $totalBalance, 2, '.', ''),
                'currency' => 'EGP',
            ],
        ];
    }

    // ── Private Parsers ──

    /**
     * Parse CSV bank statement with configurable column mapping.
     *
     * @return array<int, array{date: string, description: ?string, reference: ?string, amount: float}>
     */
    private function parseCsv(UploadedFile $file): array
    {
        $lines = [];
        $handle = fopen($file->getRealPath(), 'r');

        if ($handle === false) {
            throw new \RuntimeException('Cannot open CSV file.');
        }

        // Read header row
        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);

            return [];
        }

        // Normalize header names for flexible mapping
        $header = array_map(fn ($h) => strtolower(trim((string) $h)), $header);
        $dateCol = $this->findColumn($header, ['date', 'تاريخ', 'value_date', 'transaction_date']);
        $descCol = $this->findColumn($header, ['description', 'وصف', 'memo', 'narrative', 'details']);
        $refCol = $this->findColumn($header, ['reference', 'مرجع', 'ref', 'check_no', 'cheque_no']);
        $amountCol = $this->findColumn($header, ['amount', 'مبلغ', 'value']);
        $debitCol = $this->findColumn($header, ['debit', 'مدين', 'withdrawal']);
        $creditCol = $this->findColumn($header, ['credit', 'دائن', 'deposit']);

        while (($row = fgetcsv($handle)) !== false) {
            if (empty(array_filter($row))) {
                continue;
            }

            $amount = 0.0;
            if ($amountCol !== null) {
                $amount = (float) str_replace([',', ' '], '', (string) ($row[$amountCol] ?? '0'));
            } elseif ($debitCol !== null && $creditCol !== null) {
                $debit = (float) str_replace([',', ' '], '', (string) ($row[$debitCol] ?? '0'));
                $credit = (float) str_replace([',', ' '], '', (string) ($row[$creditCol] ?? '0'));
                $amount = $credit - $debit;
            }

            $lines[] = [
                'date' => $dateCol !== null ? ($row[$dateCol] ?? date('Y-m-d')) : date('Y-m-d'),
                'description' => $descCol !== null ? ($row[$descCol] ?? null) : null,
                'reference' => $refCol !== null ? ($row[$refCol] ?? null) : null,
                'amount' => $amount,
            ];
        }

        fclose($handle);

        return $lines;
    }

    /**
     * Parse OFX (Open Financial Exchange) XML bank statement.
     *
     * @return array<int, array{date: string, description: ?string, reference: ?string, amount: float}>
     */
    private function parseOfx(UploadedFile $file): array
    {
        $content = file_get_contents($file->getRealPath());
        if ($content === false) {
            throw new \RuntimeException('Cannot read OFX file.');
        }

        $lines = [];

        // OFX can be SGML-like, try to extract STMTTRN blocks
        preg_match_all('/<STMTTRN>(.*?)<\/STMTTRN>/s', $content, $matches);

        if (empty($matches[1])) {
            // Try SGML format (no closing tags)
            preg_match_all('/<STMTTRN>(.*?)(?=<STMTTRN>|<\/BANKTRANLIST>)/s', $content, $matches);
        }

        foreach ($matches[1] ?? [] as $block) {
            $date = $this->extractOfxField($block, 'DTPOSTED');
            $amount = $this->extractOfxField($block, 'TRNAMT');
            $name = $this->extractOfxField($block, 'NAME');
            $memo = $this->extractOfxField($block, 'MEMO');
            $fitid = $this->extractOfxField($block, 'FITID');

            if ($date && $amount) {
                $lines[] = [
                    'date' => substr($date, 0, 4).'-'.substr($date, 4, 2).'-'.substr($date, 6, 2),
                    'description' => $name ?: $memo,
                    'reference' => $fitid,
                    'amount' => (float) $amount,
                ];
            }
        }

        return $lines;
    }

    /**
     * Parse MT940 (SWIFT) bank statement.
     *
     * @return array<int, array{date: string, description: ?string, reference: ?string, amount: float}>
     */
    private function parseMt940(UploadedFile $file): array
    {
        $content = file_get_contents($file->getRealPath());
        if ($content === false) {
            throw new \RuntimeException('Cannot read MT940 file.');
        }

        $lines = [];

        // Match :61: transaction lines — format: YYMMDD[MMDD]C/DamountN...
        preg_match_all('/:61:(\d{6})\d{0,4}(C|D|RC|RD)([\d,\.]+)N.{3}(.*?)(?=\r?\n)/m', $content, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $dateStr = $match[1];
            $dcMark = $match[2]; // C=credit, D=debit, RC=reversal credit, RD=reversal debit
            $amount = (float) str_replace(',', '.', $match[3]);
            $reference = trim($match[4]);

            // Debit = negative
            if ($dcMark === 'D' || $dcMark === 'RD') {
                $amount = -$amount;
            }

            $year = (int) substr($dateStr, 0, 2);
            $year = $year > 50 ? 1900 + $year : 2000 + $year;

            $lines[] = [
                'date' => $year.'-'.substr($dateStr, 2, 2).'-'.substr($dateStr, 4, 2),
                'description' => $reference,
                'reference' => $reference,
                'amount' => $amount,
            ];
        }

        return $lines;
    }

    /**
     * Generate SWIFT MT103 payment instruction.
     */
    private function generateMt103(array $data): string
    {
        $ref = $data['reference'] ?? 'REF'.date('YmdHis');
        $date = $data['date'] ?? date('ymd');
        $currency = $data['currency'] ?? 'EGP';
        $amount = number_format((float) ($data['amount'] ?? 0), 2, ',', '');
        $senderName = $data['sender_name'] ?? '';
        $senderAccount = $data['sender_account'] ?? '';
        $receiverName = $data['receiver_name'] ?? '';
        $receiverAccount = $data['receiver_account'] ?? '';
        $receiverBank = $data['receiver_bank_code'] ?? '';
        $details = $data['details'] ?? '';

        return implode("\r\n", [
            '{1:F01XXXXEGCAXXX0000000000}',
            '{2:I103'.$receiverBank.'N}',
            '{4:',
            ':20:'.$ref,
            ':23B:CRED',
            ':32A:'.$date.$currency.$amount,
            ':50K:/'.$senderAccount,
            $senderName,
            ':59:/'.$receiverAccount,
            $receiverName,
            ':70:'.$details,
            ':71A:OUR',
            '-}',
        ]);
    }

    /**
     * Generate local Egyptian bank transfer instruction.
     */
    private function generateLocalTransfer(array $data): string
    {
        $date = $data['date'] ?? date('Y-m-d');
        $amount = number_format((float) ($data['amount'] ?? 0), 2);
        $currency = $data['currency'] ?? 'EGP';

        return implode("\n", [
            'بيانات التحويل المحلي',
            '======================',
            'التاريخ: '.$date,
            'المبلغ: '.$amount.' '.$currency,
            'من حساب: '.($data['sender_account'] ?? ''),
            'اسم المرسل: '.($data['sender_name'] ?? ''),
            'إلى حساب: '.($data['receiver_account'] ?? ''),
            'اسم المستفيد: '.($data['receiver_name'] ?? ''),
            'بنك المستفيد: '.($data['receiver_bank_code'] ?? ''),
            'الغرض: '.($data['details'] ?? ''),
        ]);
    }

    /**
     * Find column index from possible header names.
     *
     * @param  array<int, string>  $header
     * @param  array<int, string>  $possibleNames
     */
    private function findColumn(array $header, array $possibleNames): ?int
    {
        foreach ($possibleNames as $name) {
            $index = array_search($name, $header, true);
            if ($index !== false) {
                return (int) $index;
            }
        }

        return null;
    }

    /**
     * Extract a field value from an OFX block.
     */
    private function extractOfxField(string $block, string $field): ?string
    {
        // Try XML style first
        if (preg_match("/<{$field}>(.*?)<\/{$field}>/s", $block, $match)) {
            return trim($match[1]);
        }
        // Try SGML style (no closing tag)
        if (preg_match("/<{$field}>(.*?)(?=<|\r?\n)/s", $block, $match)) {
            return trim($match[1]);
        }

        return null;
    }
}
