<?php

namespace App\Services\Receipts;

use App\Models\Receipt;
use App\Models\Statement;
use App\Models\StatementLine;
use App\Services\Banking\StatementImporter;
use App\Services\Banking\StatementPositions;
use App\Services\Banking\VisecaImporter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Proposes which bank or card row paid which receipt. It only writes proposals to ernte's
 * own database; nothing in Dropbox changes until Sam numbers a confirmed match.
 */
class ReceiptRowMatcher
{
    /** A row is a candidate from this many days before the receipt's date … */
    public const DAYS_BEFORE = 10;

    /** … to this many days after it (card charges reach a bill weeks later). */
    public const DAYS_AFTER = 75;

    private const STOPWORDS = [
        'gmbh', 'inc', 'ltd', 'llc', 'the', 'und', 'and', 'von', 'com', 'www', 'online', 'payment', 'services',
        'rechnung', 'invoice', 'receipt', 'order', 'beleg', 'quittung', 'bill', 'pdf', 'scan', 'your',
        'belastung', 'mobile', 'banking', 'ebanking', 'auftrags', 'einkauf', 'card', 'debit', 'visa', 'abonnement',
        'schweiz', 'switzerland', 'suisse', 'zurich', 'zuerich',
    ];

    public function __construct(private StatementPositions $positions) {}

    /**
     * Idempotent: earlier proposals are recomputed, confirmed matches are never touched.
     *
     * @return array{proposed: int, confident: int, open_receipts: int}
     */
    public function run(): array
    {
        return DB::transaction(function () {
            Receipt::where('match_state', 'proposed')->update([
                'match_state' => null, 'statement_line_id' => null, 'match_method' => null, 'match_note' => null, 'match_confident' => false,
            ]);

            $receipts = Receipt::where('extraction_status', 'done')->whereNull('duplicate_of_id')
                ->whereNull('match_state')->where('auto_match_disabled', false)
                ->orderBy('document_date')->orderBy('id')->get()->keyBy('id');

            $rows = StatementLine::where('is_credit', false)->where('is_reversal', false)
                ->where('is_fee', false)->where('no_receipt', false)
                ->where(fn ($q) => $q->where('source', StatementImporter::SOURCE)
                    ->orWhere(fn ($c) => $c->where('source', VisecaImporter::SOURCE)->where('bank_tx_code', 'CARD')))
                ->whereDoesntHave('receipts', fn ($q) => $q->where('match_state', 'matched'))
                ->orderBy('booked_on')->orderBy('id')->get()->keyBy('id');

            $proposed = $confident = 0;
            $numbers = $this->numberIndex();
            $propose = function (Receipt $receipt, StatementLine $row, string $method, bool $sure, ?string $note) use (&$receipts, &$rows, &$proposed, &$confident, $numbers) {
                // The file already carries a number, but the amounts point at another row:
                // the old PDF order and the bank's order differ here. Never confident.
                if ($method !== 'existing_prefix' && $receipt->numberPrefix() !== null && $this->rowForExistingPrefix($receipt, $numbers) !== $row->id) {
                    $sure = false;
                    $note = 'number_differs';
                }
                $receipt->update([
                    'statement_line_id' => $row->id, 'match_state' => 'proposed', 'match_method' => $method,
                    'match_confident' => $sure, 'match_note' => $note,
                ]);
                $receipts->forget($receipt->id);
                $rows->forget($row->id);
                $proposed++;
                $confident += $sure ? 1 : 0;
            };

            // Pass 1 — the file already carries a number (from the scripts or by hand), and
            // an amount on it equals the row with that number. A number whose row shows a
            // different amount is held back: the amount passes below may find the true row.
            $numberOnly = [];
            foreach ($receipts->all() as $receipt) {
                $row = $this->rowForExistingPrefix($receipt, $numbers);
                if (! $row || ! $rows->has($row)) {
                    continue;
                }
                if ($this->anyAmount($receipt, $rows[$row], true)) {
                    $propose($receipt, $rows[$row], 'existing_prefix', true, null);
                } else {
                    $numberOnly[$receipt->id] = $row;
                }
            }

            $passes = [
                // Pass 2 — the bank payment's reference is printed on the receipt.
                ['reference', true, fn (Receipt $r, StatementLine $l) => $this->referenceOnReceipt($r, $l) && $this->anyAmount($r, $l, true)],
                // Pass 3 — the stated total, and the names agree.
                ['total_and_name', true, fn (Receipt $r, StatementLine $l) => $this->inWindow($r, $l) && $this->totalEquals($r, $l) && $this->namesAgree($r, $l)],
                // Pass 4 — the stated total alone.
                ['total', true, fn (Receipt $r, StatementLine $l) => $this->inWindow($r, $l) && $this->totalEquals($r, $l)],
                // Pass 5 — another amount on the receipt, and the names agree.
                ['amount_and_name', false, fn (Receipt $r, StatementLine $l) => $this->inWindow($r, $l) && $this->anyAmount($r, $l, false) && $this->namesAgree($r, $l)],
                // Pass 6 — foreign-currency purchase by debit card: the bank only shows CHF.
                ['name_and_date', false, fn (Receipt $r, StatementLine $l) => $l->source === StatementImporter::SOURCE && $l->bank_tx_code === 'POSD'
                    && $r->currency !== null && $r->currency !== 'CHF' && $r->document_date !== null && $this->inWindow($r, $l) && $this->namesAgree($r, $l)],
            ];

            foreach ($passes as [$method, $canBeSure, $fits]) {
                foreach ($this->components($receipts, $rows, $fits) as [$componentReceipts, $componentRows]) {
                    $note = $method === 'name_and_date' ? 'amount_differs' : null;
                    if (count($componentReceipts) === 1 && count($componentRows) === 1) {
                        // Without a date on the receipt, an amount alone is not enough to be sure.
                        $sure = $canBeSure && ($method === 'reference' || $componentReceipts[0]->document_date !== null);
                        $propose($componentReceipts[0], $componentRows[0], $method, $sure, $note);
                    } elseif (count($componentReceipts) === count($componentRows) && $method !== 'total' && $this->allDated($componentReceipts)) {
                        // The same subscription several times: pair them in date order.
                        foreach ($componentReceipts as $i => $receipt) {
                            $propose($receipt, $componentRows[$i], $method, $canBeSure, $note);
                        }
                    } else {
                        // Unclear: offer the nearest row in time and say so.
                        $free = collect($componentRows);
                        foreach ($componentReceipts as $receipt) {
                            $nearest = $free->filter(fn (StatementLine $l) => $fits($receipt, $l))
                                ->sortBy(fn (StatementLine $l) => $receipt->document_date ? abs($receipt->document_date->diffInDays($l->booked_on, false)) : 0)->first();
                            if ($nearest) {
                                $propose($receipt, $nearest, $method, false, $note ?? 'several_rows');
                                $free = $free->reject(fn (StatementLine $l) => $l->id === $nearest->id);
                            }
                        }
                    }
                }
            }

            // Numbered files no amount could place: offer the row their number names, flagged.
            // Contracts and salary slips legitimately show other figures than the payment.
            foreach ($numberOnly as $receiptId => $rowId) {
                if ($receipts->has($receiptId) && $rows->has($rowId)) {
                    $propose($receipts[$receiptId], $rows[$rowId], 'existing_prefix', false, 'number_only');
                }
            }

            return ['proposed' => $proposed, 'confident' => $confident, 'open_receipts' => $receipts->count()];
        });
    }

    /** Sam's decision: this receipt belongs to this row. Several receipts may share a row. */
    public function confirm(Receipt $receipt, StatementLine $row, ?string $method = null): void
    {
        if ($row->is_credit && $row->source === StatementImporter::SOURCE) {
            throw new \DomainException('A bank credit is an incoming payment; receipts belong to debits.');
        }
        if ($receipt->numbered_at !== null && $receipt->statement_line_id !== $row->id) {
            throw new \DomainException('This receipt is already numbered; undo that first.');
        }

        $keepsProposal = $receipt->match_state === 'proposed' && $receipt->statement_line_id === $row->id;
        $receipt->update([
            'statement_line_id' => $row->id, 'match_state' => 'matched',
            'match_method' => $method ?? ($keepsProposal ? $receipt->match_method : 'manual'),
            'match_note' => null,
        ]);
    }

    /** Remove a match; the receipt is then left to Sam, or the next run would repeat it. */
    public function unmatch(Receipt $receipt): void
    {
        if ($receipt->numbered_at !== null) {
            throw new \DomainException('This receipt is already numbered in Dropbox; undo the numbering first.');
        }

        $receipt->update([
            'statement_line_id' => null, 'match_state' => null, 'match_method' => null,
            'match_note' => null, 'match_confident' => false, 'auto_match_disabled' => true,
        ]);
    }

    // ── Predicates ──

    private function inWindow(Receipt $receipt, StatementLine $row): bool
    {
        if (! $receipt->document_date) {
            return true;
        }
        $days = $receipt->document_date->diffInDays($row->booked_on, false);

        return $days >= -self::DAYS_BEFORE && $days <= self::DAYS_AFTER;
    }

    private function totalEquals(Receipt $receipt, StatementLine $row): bool
    {
        return $receipt->total_minor !== null && $this->amountFits($row, $receipt->total_minor, $receipt->currency);
    }

    /** Any figure printed on the receipt; with $includeTotal false, only the others. */
    private function anyAmount(Receipt $receipt, StatementLine $row, bool $includeTotal): bool
    {
        if ($includeTotal && $this->totalEquals($receipt, $row)) {
            return true;
        }
        foreach ($receipt->amounts ?? [] as $amount) {
            $minor = (int) round(((float) ($amount['amount'] ?? 0)) * 100);
            if ($minor > 0 && $minor !== $receipt->total_minor && $this->amountFits($row, $minor, $amount['currency'] ?? $receipt->currency)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Card rows are compared in the currency of the purchase, which the export carries
     * exactly; the CHF figure is rounded to 5 rappen and only a fallback for CHF receipts.
     */
    private function amountFits(StatementLine $row, int $minor, ?string $currency): bool
    {
        if ($row->source === VisecaImporter::SOURCE) {
            if ($row->original_amount_minor === $minor && ($currency === null || $currency === $row->original_currency)) {
                return true;
            }

            return ($currency === null || $currency === 'CHF') && abs($row->amount_rappen - $minor) <= 5 && $row->original_currency === 'CHF';
        }

        return ($currency === null || $currency === 'CHF') && $row->amount_rappen === $minor;
    }

    private function referenceOnReceipt(Receipt $receipt, StatementLine $row): bool
    {
        $reference = preg_replace('/\s+/', '', (string) $row->creditor_reference);
        if (strlen($reference) < 10 || ! $receipt->text_layer) {
            return false;
        }

        return str_contains(preg_replace('/\s+/', '', $receipt->text_layer), $reference);
    }

    private function namesAgree(Receipt $receipt, StatementLine $row): bool
    {
        $a = self::words($receipt->vendor.' '.$receipt->original_name.' '.$receipt->filename);
        $b = self::words($row->counterparty_name.' '.$row->description.' '.$row->remittance_text);
        foreach ($a as $x) {
            foreach ($b as $y) {
                if (str_contains($x, $y) || str_contains($y, $x)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Significant lowercase words: four letters or more, no boilerplate, no pure numbers. */
    public static function words(string $text): array
    {
        $tokens = preg_split('/[^a-z0-9]+/', Str::lower(Str::ascii($text)), -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_unique(array_filter($tokens, fn (string $t) => strlen($t) >= 4 && ! ctype_digit($t)
            && ! preg_match('/\d{3,}/', $t) && ! in_array($t, self::STOPWORDS, true))));
    }

    private function allDated(array $receipts): bool
    {
        return collect($receipts)->every(fn (Receipt $r) => $r->document_date !== null);
    }

    /**
     * Groups of receipts and rows connected by "this row could have paid this receipt".
     *
     * @return list<array{0: list<Receipt>, 1: list<StatementLine>}> each sorted by date
     */
    private function components(Collection $receipts, Collection $rows, callable $fits): array
    {
        $edges = [];
        foreach ($receipts as $receipt) {
            foreach ($rows as $row) {
                if ($fits($receipt, $row)) {
                    $edges["r{$receipt->id}"][] = "l{$row->id}";
                    $edges["l{$row->id}"][] = "r{$receipt->id}";
                }
            }
        }

        $seen = [];
        $components = [];
        foreach (array_keys($edges) as $start) {
            if (isset($seen[$start])) {
                continue;
            }
            $stack = [$start];
            $r = $l = [];
            while ($stack) {
                $node = array_pop($stack);
                if (isset($seen[$node])) {
                    continue;
                }
                $seen[$node] = true;
                $id = (int) substr($node, 1);
                $node[0] === 'r' ? $r[] = $receipts[$id] : $l[] = $rows[$id];
                array_push($stack, ...$edges[$node]);
            }
            usort($r, fn (Receipt $a, Receipt $b) => [$a->document_date?->timestamp ?? 0, $a->id] <=> [$b->document_date?->timestamp ?? 0, $b->id]);
            usort($l, fn (StatementLine $a, StatementLine $b) => [($a->transacted_at ?? $a->booked_on)->timestamp, $a->id] <=> [($b->transacted_at ?? $b->booked_on)->timestamp, $b->id]);
            $components[] = [$r, $l];
        }

        return $components;
    }

    // ── Existing prefixes ──

    /** "2026-07" => [number => line id] for bank months, "2026-07/K" for the card bill of that month. */
    private function numberIndex(): array
    {
        $index = [];
        foreach (StatementLine::where('source', StatementImporter::SOURCE)->selectRaw('DISTINCT YEAR(booked_on) y, MONTH(booked_on) m')->get() as $ym) {
            foreach ($this->positions->bankMonth((int) $ym->y, (int) $ym->m) as $line) {
                $index[sprintf('%d-%02d', $ym->y, $ym->m)][$line->number] = $line->id;
            }
        }
        foreach (Statement::where('source', VisecaImporter::SOURCE)->whereNotNull('bank_line_id')->with('bankLine')->get() as $bill) {
            $key = $bill->bankLine->booked_on->format('Y-m').'/K';
            foreach ($this->positions->bill($bill) as $line) {
                if ($line->number !== null) {
                    $index[$key][$line->number] = $line->id;
                }
            }
        }

        return $index;
    }

    private function rowForExistingPrefix(Receipt $receipt, array $numbers): ?int
    {
        if (! preg_match('#/(\d{4})_Q\d/(\d{2})/(Kreditkarte/)?(\d{2,3})_[^/]+$#i', (string) $receipt->dropbox_path, $m)) {
            return null;
        }

        return $numbers["{$m[1]}-{$m[2]}".(($m[3] ?? '') !== '' ? '/K' : '')][(int) $m[4]] ?? null;
    }
}
