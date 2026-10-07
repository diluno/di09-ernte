<?php

namespace App\Services\Banking;

use App\Models\Invoice;
use App\Models\StatementLine;
use App\Services\Invoicing\InvoiceLifecycle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Matches bank credits to invoices. Confident matches are applied (the invoice is marked
 * paid on the booking date); the rest become proposals for the user to decide.
 */
class InvoicePaymentMatcher
{
    /**
     * An invoice already marked paid by hand is only an amount candidate for credits booked
     * within this many days of its paid date. Without the window, every old invoice with a
     * recurring amount would compete for each new payment of that amount.
     */
    public const PAID_WINDOW_DAYS = 14;

    public function __construct(private InvoiceLifecycle $lifecycle) {}

    /**
     * Idempotent. Each pass runs over all remaining credits before the next starts, so a
     * weak rule never takes an invoice that a stronger rule would give to another credit.
     *
     * @return array{matched: int, proposed: int, unmatched: int}
     */
    public function run(): array
    {
        return DB::transaction(function () {
            StatementLine::where('match_state', 'proposed')->update([
                'match_state' => 'unmatched', 'invoice_id' => null, 'match_note' => null,
            ]);

            /** @var Collection<int, StatementLine> $lines */
            $lines = StatementLine::where('match_state', 'unmatched')
                ->where('is_credit', true)->where('is_reversal', false)
                ->whereNull('transactions')->where('auto_match_disabled', false)
                ->orderBy('booked_on')->orderBy('id')
                ->get()->keyBy('id');

            /** @var Collection<int, Invoice> $invoices */
            $invoices = Invoice::whereIn('status', ['sent', 'paid'])
                ->whereDoesntHave('bankEntries', fn ($q) => $q->where('match_state', 'matched'))
                ->orderBy('issued_on')->orderBy('id')
                ->get()->keyBy('id');

            $matched = 0;
            $hints = [];
            $take = function (StatementLine $line, Invoice $invoice, string $method) use (&$lines, &$invoices, &$matched) {
                $this->apply($line, $invoice, $method);
                $lines->forget($line->id);
                $invoices->forget($invoice->id);
                $matched++;
            };

            // Pass 1 — QR reference.
            foreach ($lines->all() as $line) {
                if (! $line->creditor_reference) {
                    continue;
                }
                $invoice = $invoices->firstWhere('qr_reference', $line->creditor_reference);
                if (! $invoice) {
                    continue;
                }
                if ($invoice->total_rappen === $line->amount_rappen) {
                    $take($line, $invoice, 'qr_reference');
                } else {
                    $hints[$line->id] = $invoice->id;
                }
            }

            // Pass 2 — invoice number in the payer's text.
            foreach ($lines->all() as $line) {
                if (! $line->remittance_text) {
                    continue;
                }
                $named = $invoices->filter(fn (Invoice $i) => self::textNames($line->remittance_text, $i->number));
                $exact = $named->where('total_rappen', $line->amount_rappen);
                if ($exact->count() === 1) {
                    $take($line, $exact->first(), 'number_in_text');
                } elseif ($named->isNotEmpty()) {
                    $hints[$line->id] ??= $named->first()->id;
                }
            }

            // Pass 3 — amount fits exactly one invoice, and no other credit wants that invoice.
            // Repeats because each match can leave another credit with a single candidate.
            do {
                $single = [];
                foreach ($lines as $line) {
                    $candidates = $this->amountCandidates($line, $invoices);
                    if ($candidates->count() === 1) {
                        $single[$candidates->first()->id][] = $line;
                    }
                }
                $progress = false;
                foreach ($single as $invoiceId => $wanting) {
                    if (count($wanting) === 1) {
                        $take($wanting[0], $invoices[$invoiceId], 'amount');
                        $progress = true;
                    }
                }
            } while ($progress);

            // Whatever is left with a plausible invoice becomes a proposal.
            $proposed = 0;
            foreach ($lines as $line) {
                $candidates = $this->amountCandidates($line, $invoices);
                if ($candidates->isNotEmpty()) {
                    $line->update(['match_state' => 'proposed', 'invoice_id' => $candidates->last()->id, 'match_note' => 'several_candidates']);
                    $proposed++;
                } elseif (isset($hints[$line->id]) && $invoices->has($hints[$line->id])) {
                    $line->update(['match_state' => 'proposed', 'invoice_id' => $hints[$line->id], 'match_note' => 'amount_mismatch']);
                    $proposed++;
                }
            }

            return [
                'matched' => $matched,
                'proposed' => $proposed,
                'unmatched' => StatementLine::where('match_state', 'unmatched')->count(),
            ];
        });
    }

    /** Link a credit to an invoice and make the invoice paid on the booking date. */
    public function apply(StatementLine $line, Invoice $invoice, string $method): void
    {
        if (! $line->is_credit) {
            throw new \DomainException('Only a credit can be matched to an invoice.');
        }
        if ($line->match_state === 'matched') {
            throw new \DomainException('This entry is already matched; undo that first.');
        }
        if (! in_array($invoice->status, ['sent', 'paid'], true)) {
            throw new \DomainException("Invoice {$invoice->number} is {$invoice->status} and cannot receive a payment.");
        }

        DB::transaction(function () use ($line, $invoice, $method) {
            // Noon keeps the calendar day stable whichever timezone reads it back.
            $paidOn = Carbon::parse($line->booked_on->toDateString().' 12:00:00');
            $payload = ['statement_line_id' => $line->id, 'method' => $method];
            $markedPaid = false;

            if ($invoice->status === 'sent') {
                $this->lifecycle->markPaid($invoice, $paidOn, $payload);
                $markedPaid = true;
            } else {
                // A second credit for the same invoice (double payment) must not move the date again.
                $first = ! $invoice->bankEntries()->where('match_state', 'matched')->exists();
                $this->lifecycle->recordBankPayment($invoice, $paidOn, $payload, $first);
            }

            $line->update([
                'invoice_id' => $invoice->id,
                'match_state' => 'matched',
                'match_method' => $method,
                'match_note' => null,
                'marked_invoice_paid' => $markedPaid,
            ]);
        });
    }

    /**
     * Undo a match. The invoice goes back to "sent" only if this match had marked it paid.
     * The entry is excluded from automatic matching afterwards, or the next run would
     * simply repeat the match that was just rejected.
     */
    public function unmatch(StatementLine $line): void
    {
        if ($line->match_state !== 'matched') {
            throw new \DomainException('This entry is not matched.');
        }

        DB::transaction(function () use ($line) {
            $invoice = $line->invoice;
            if ($line->marked_invoice_paid && $invoice?->status === 'paid') {
                $this->lifecycle->reopen($invoice);
            }
            $line->update([
                'invoice_id' => null, 'match_state' => 'unmatched', 'match_method' => null,
                'match_note' => null, 'marked_invoice_paid' => false, 'auto_match_disabled' => true,
            ]);
        });
    }

    /** Mark a credit as "not an invoice payment", or bring it back. */
    public function setIgnored(StatementLine $line, bool $ignored): void
    {
        if (! $line->is_credit) {
            throw new \DomainException('Only a credit can be ignored.');
        }
        if ($line->match_state === 'matched') {
            throw new \DomainException('This entry is matched; undo that first.');
        }
        if (! $ignored && $line->match_state !== 'ignored') {
            return;
        }

        $line->update([
            'match_state' => $ignored ? 'ignored' : 'unmatched',
            'invoice_id' => null, 'match_note' => null,
        ]);
    }

    /** True when $number appears in $text and is not part of a longer run of digits. */
    public static function textNames(string $text, string $number): bool
    {
        if (strlen($number) < 3) {
            return false;
        }

        return preg_match('/(?<![0-9])'.preg_quote($number, '/').'(?![0-9])/i', $text) === 1;
    }

    /**
     * @param  Collection<int, Invoice>  $invoices
     * @return Collection<int, Invoice>
     */
    private function amountCandidates(StatementLine $line, Collection $invoices): Collection
    {
        return $invoices->filter(function (Invoice $invoice) use ($line) {
            if ($invoice->total_rappen !== $line->amount_rappen) {
                return false;
            }
            if (! $invoice->issued_on || $invoice->issued_on->gt($line->booked_on)) {
                return false;
            }
            if ($invoice->status === 'paid') {
                return $invoice->paid_at !== null
                    && abs($invoice->paid_at->copy()->startOfDay()->diffInDays($line->booked_on, false)) <= self::PAID_WINDOW_DAYS;
            }

            return true;
        });
    }
}
