<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Receipt;
use App\Models\StandingDocument;
use App\Models\Statement;
use App\Models\StatementLine;
use App\Services\Banking\CamtException;
use App\Services\Banking\InvoicePaymentMatcher;
use App\Services\Banking\StatementImporter;
use App\Services\Banking\StatementPositions;
use App\Services\Banking\VisecaImporter;
use App\Services\Dropbox\DropboxException;
use App\Services\Receipts\NumberedCopies;
use App\Services\Receipts\ReceiptNumberer;
use App\Services\Receipts\ReceiptRowMatcher;
use App\Support\Quarter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class BankController extends Controller
{
    public function index(Request $request, StatementPositions $positions): Response
    {
        $this->standing = StandingDocument::where('active', true)->get();

        // The page shows one quarter at a time, the unit the accountant works in.
        $quarters = StatementLine::where('source', StatementImporter::SOURCE)
            ->selectRaw('DISTINCT YEAR(booked_on) AS y, QUARTER(booked_on) AS q')->orderByDesc('y')->orderByDesc('q')->get()
            ->map(fn ($r) => new Quarter((int) $r->y, (int) $r->q));
        $quarter = Quarter::parse($request->string('quarter')->toString())
            // Older links carry only a year: open its latest quarter.
            ?? ($request->integer('year') ? $quarters->first(fn (Quarter $q) => $q->year === $request->integer('year')) : null)
            ?? $quarters->first()
            ?? Quarter::of(now());
        $year = $quarter->year;
        $with = ['invoice.client:id,name', 'receipts'];

        $bills = Statement::where('source', VisecaImporter::SOURCE)->whereNotNull('bank_line_id')->with('bankLine')->get()
            ->groupBy(fn (Statement $b) => $b->bankLine->booked_on->format('Y-m'));
        $billByDebit = $bills->flatten()->keyBy('bank_line_id');

        $monthKeys = StatementLine::where('source', StatementImporter::SOURCE)->whereYear('booked_on', $year)
            ->whereIn(\Illuminate\Support\Facades\DB::raw('MONTH(booked_on)'), $quarter->months())
            ->selectRaw('DISTINCT MONTH(booked_on) AS m')->orderByDesc('m')->pluck('m');

        $months = [];
        foreach ($monthKeys as $m) {
            $key = sprintf('%d-%02d', $year, $m);
            $lines = $positions->bankMonth($year, (int) $m)->load($with);
            $sections = [[
                'kind' => 'bank', 'title' => null, 'bill_id' => null,
                'lines' => $lines->map(fn (StatementLine $l) => $this->line($l, $l->number, $billByDebit))->all(),
            ]];
            foreach ($bills[$key] ?? [] as $bill) {
                $sections[] = [
                    'kind' => 'card', 'bill_id' => $bill->id,
                    'paid_on' => $bill->bankLine->booked_on->toDateString(),
                    'total' => round($bill->charges_rappen / 100, 2),
                    'title' => 'Kreditkarte · bill paid '.$bill->bankLine->booked_on->format('d.m.Y').' · CHF '.number_format($bill->charges_rappen / 100, 2, '.', "'"),
                    'lines' => $positions->bill($bill)->load($with)->map(fn (StatementLine $l) => $this->line($l, $l->number, $billByDebit))->all(),
                ];
            }
            $all = collect($sections)->flatMap(fn ($s) => $s['lines']);
            $months[] = [
                'key' => $key,
                'label' => Carbon::create($year, (int) $m, 1)->format('F Y'),
                'complete' => $positions->bankMonthComplete($year, (int) $m),
                // Kept for the original flat list; the page renders `sections`.
                'lines' => $sections[0]['lines'],
                'sections' => $sections,
                'missing' => $all->filter(fn ($l) => $l['needs_receipt'] && $l['creates'] === null && collect($l['receipts'])->where('state', 'matched')->isEmpty())->count(),
                'proposed' => $all->sum(fn ($l) => collect($l['receipts'])->where('state', 'proposed')->count()),
                'confident' => $all->sum(fn ($l) => collect($l['receipts'])->where('state', 'proposed')->where('confident', true)->count()),
                'to_number' => $all->sum(fn ($l) => collect($l['receipts'])->where('state', 'matched')->where('numbered', false)->count())
                    + $all->filter(fn ($l) => $l['creates'] !== null)->count(),
            ];
        }

        $review = StatementLine::whereIn('match_state', ['proposed', 'unmatched'])
            ->where('is_credit', true)->where('source', StatementImporter::SOURCE)
            ->whereYear('booked_on', $year)->whereIn(\Illuminate\Support\Facades\DB::raw('MONTH(booked_on)'), $quarter->months())
            ->with($with)
            ->orderBy('booked_on')->orderBy('id')
            ->get()
            ->map(fn (StatementLine $l) => $this->line($l, null, $billByDebit))
            ->all();

        $pool = StatementLine::where('source', VisecaImporter::SOURCE)->where('bank_tx_code', 'CARD')
            ->whereHas('statement', fn ($q) => $q->whereNull('bank_line_id'))
            ->with($with)->orderBy('transacted_at')->orderBy('bank_ref')->get()
            ->map(fn (StatementLine $l) => $this->line($l, null, $billByDebit))->all();

        return Inertia::render('Bank/Index', [
            'year' => $year,
            'quarter' => $quarter->toArray(),
            'quarters' => $quarters->map->toArray()->all(),
            'months' => $months,
            'review' => $review,
            'pool' => $pool,
            'gaps' => StatementImporter::gaps(),
            'open_receipts' => Receipt::whereNull('match_state')->whereNull('duplicate_of_id')
                ->orderByDesc('document_date')->orderByDesc('id')->limit(300)->get()
                ->map(fn (Receipt $r) => [
                    'id' => $r->id,
                    'label' => trim(($r->document_date?->format('d.m.Y') ?? 'no date').' · '.($r->vendor ?? $r->original_name).' · '
                        .($r->total_minor !== null ? number_format($r->total_minor / 100, 2, '.', "'").' '.$r->currency : 'no total')),
                ])->all(),
            'invoice_options' => Invoice::whereIn('status', ['sent', 'paid'])
                ->with('client:id,name')
                ->orderByDesc('issued_on')->orderByDesc('id')
                ->limit(300)
                ->get()
                ->map(fn (Invoice $i) => [
                    'id' => $i->id,
                    'number' => $i->number,
                    'client' => $i->client?->name,
                    'total' => round($i->total_rappen / 100, 2),
                    'status' => $i->status,
                ])->all(),
        ]);
    }

    /** One batch of statement files. The page posts large uploads in several batches. */
    public function upload(Request $request, StatementImporter $importer, VisecaImporter $viseca): JsonResponse
    {
        $request->validate([
            'files' => 'required|array|max:20',
            'files.*' => 'file|max:5120',
        ]);

        $files = [];
        foreach ($request->file('files') as $file) {
            $name = $file->getClientOriginalName();
            try {
                $contents = $file->get();
                $files[] = ['name' => $name, 'ok' => true] + (VisecaImporter::looksLikeCsv($contents)
                    ? $viseca->import($contents, $name) + ['kind' => 'card']
                    : $importer->import($contents, $name) + ['kind' => 'bank']);
            } catch (CamtException $e) {
                $files[] = ['name' => $name, 'ok' => false, 'reason' => $e->getMessage()];
            }
        }

        return response()->json(['files' => $files]);
    }

    /** Everything that follows an import: invoice payments, card bills, receipt proposals. */
    public function runMatching(InvoicePaymentMatcher $matcher, VisecaImporter $viseca, ReceiptRowMatcher $receipts): JsonResponse
    {
        $invoices = $matcher->run();
        $bills = $viseca->assignBills();

        return response()->json($invoices + ['bills_assigned' => $bills, 'receipts' => $receipts->run()]);
    }

    public function matchReceipt(Request $request, Receipt $receipt, ReceiptRowMatcher $matcher): RedirectResponse
    {
        $data = $request->validate(['line_id' => 'required|integer|exists:statement_lines,id']);
        try {
            $matcher->confirm($receipt, StatementLine::findOrFail($data['line_id']));
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back();
    }

    public function unmatchReceipt(Receipt $receipt, ReceiptRowMatcher $matcher): RedirectResponse
    {
        try {
            $matcher->unmatch($receipt);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back();
    }

    /** Confirm every confident proposal of one month (bank rows and the card bills under it). */
    public function confirmConfident(string $month, ReceiptRowMatcher $matcher): RedirectResponse
    {
        abort_unless(preg_match('/^(\d{4})-(\d{2})$/', $month, $m), 404);

        $billIds = Statement::where('source', VisecaImporter::SOURCE)->whereNotNull('bank_line_id')
            ->whereHas('bankLine', fn ($q) => $q->whereYear('booked_on', (int) $m[1])->whereMonth('booked_on', (int) $m[2]))->pluck('id');
        $receipts = Receipt::where('match_state', 'proposed')->where('match_confident', true)
            ->whereHas('statementLine', fn ($q) => $q
                ->where(fn ($b) => $b->where('source', StatementImporter::SOURCE)->whereYear('booked_on', (int) $m[1])->whereMonth('booked_on', (int) $m[2]))
                ->orWhereIn('statement_id', $billIds))
            ->with('statementLine')->get();

        foreach ($receipts as $receipt) {
            $matcher->confirm($receipt, $receipt->statementLine);
        }

        return back()->with('success', $receipts->count().' match(es) confirmed.');
    }

    /** Write the numbers of one month into Dropbox: every confirmed receipt gets its prefix. */
    public function numberMonth(string $month, ReceiptNumberer $numberer, NumberedCopies $copies): RedirectResponse
    {
        abort_unless(preg_match('/^(\d{4})-(\d{2})$/', $month, $m), 404);

        $result = array_merge_recursive($numberer->numberMonth((int) $m[1], (int) $m[2]), $copies->numberMonth((int) $m[1], (int) $m[2]));
        $result['numbered'] = array_sum((array) $result['numbered']);
        $message = "{$result['numbered']} file(s) numbered in Dropbox.";
        if ($result['skipped']) {
            return back()->with('error', $message.' Skipped: '.implode(' · ', array_slice($result['skipped'], 0, 5))
                .(count($result['skipped']) > 5 ? ' · and '.(count($result['skipped']) - 5).' more' : ''));
        }

        return back()->with('success', $message);
    }

    /** Create the numbered file ernte itself provides for a row: rent contract copy or invoice PDF. */
    public function numberLine(StatementLine $line, NumberedCopies $copies): RedirectResponse
    {
        try {
            return back()->with('success', 'Created in Dropbox: '.$copies->create($line));
        } catch (\DomainException|DropboxException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function numberReceipt(Receipt $receipt, ReceiptNumberer $numberer): RedirectResponse
    {
        try {
            $numberer->number($receipt);
        } catch (\DomainException|DropboxException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Numbered: {$receipt->filename}");
    }

    public function unnumberReceipt(Receipt $receipt, ReceiptNumberer $numberer): RedirectResponse
    {
        try {
            return back()->with('success', $numberer->undo($receipt));
        } catch (\DomainException|DropboxException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function noReceipt(Request $request, StatementLine $line): RedirectResponse
    {
        $line->update(['no_receipt' => $request->boolean('no_receipt', true)]);

        return back();
    }

    public function dissolveBill(Statement $statement, VisecaImporter $viseca): RedirectResponse
    {
        try {
            $viseca->dissolve($statement);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Card bill dissolved; its rows are unbilled again.');
    }

    public function match(Request $request, StatementLine $line, InvoicePaymentMatcher $matcher): RedirectResponse
    {
        $data = $request->validate(['invoice_id' => 'required|integer|exists:invoices,id']);
        $invoice = Invoice::findOrFail($data['invoice_id']);
        // Confirming a proposal keeps "proposed by the matcher" out of the record: the user decided.
        try {
            $matcher->apply($line, $invoice, 'manual');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Payment matched to invoice {$invoice->number}.");
    }

    public function unmatch(StatementLine $line, InvoicePaymentMatcher $matcher): RedirectResponse
    {
        try {
            $matcher->unmatch($line);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Match removed.');
    }

    public function ignore(Request $request, StatementLine $line, InvoicePaymentMatcher $matcher): RedirectResponse
    {
        try {
            $matcher->setIgnored($line, $request->boolean('ignored', true));
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back();
    }

    public function note(Request $request, StatementLine $line): RedirectResponse
    {
        $data = $request->validate(['note' => 'nullable|string|max:1000']);
        $line->update(['note' => $data['note'] ?? null]);

        return back();
    }

    private $standing = null;

    private function creates(StatementLine $line): ?array
    {
        if ($line->source !== StatementImporter::SOURCE) {
            return null;
        }
        if ($line->is_credit) {
            return $line->match_state === 'matched' && $line->invoice && $line->invoice->dropbox_file_id === null
                ? ['kind' => 'invoice', 'label' => 'invoice PDF'] : null;
        }
        if ($line->is_fee || $line->no_receipt || $line->is_reversal || ($line->relationLoaded('receipts') && $line->receipts->isNotEmpty())) {
            return null;
        }
        $document = ($this->standing ?? collect())->first(fn (StandingDocument $d) => $d->fits($line));

        return $document ? ['kind' => 'standing', 'label' => $document->label] : null;
    }

    private function line(StatementLine $line, ?int $position, $billByDebit = null): array
    {
        $isCard = $line->source === VisecaImporter::SOURCE;
        $isCardDebit = ! $isCard && ! $line->is_credit && str_contains(mb_strtolower((string) $line->counterparty_name), 'viseca');

        return [
            'id' => $line->id,
            'position' => $position,
            'source' => $line->source,
            'booked_on' => $line->booked_on->toDateString(),
            'is_credit' => $line->is_credit,
            'amount' => round($line->amount_rappen / 100, 2),
            'original' => $isCard && $line->original_currency !== 'CHF'
                ? ['amount' => round($line->original_amount_minor / 100, 2), 'currency' => $line->original_currency] : null,
            'description' => $line->description,
            'remittance_text' => $line->remittance_text,
            'counterparty' => $line->counterparty_name,
            'creditor_reference' => $line->creditor_reference,
            'bank_ref' => $line->bank_ref,
            'is_fee' => $line->is_fee,
            'is_collective' => $line->transactions !== null,
            'match_state' => $line->match_state,
            'match_method' => $line->match_method,
            'match_note' => $line->match_note,
            'note' => $line->note,
            'no_receipt' => $line->no_receipt,
            // A debit (or card charge) that should have a document in the folder.
            'needs_receipt' => ! $line->is_credit && ! $line->is_reversal && ! $line->is_fee && ! $line->no_receipt,
            // A numbered file ernte will create itself for this row.
            'creates' => $this->creates($line),
            'card_bill' => $isCardDebit ? (($billByDebit[$line->id] ?? null) ? 'imported' : 'missing') : null,
            'receipts' => $line->relationLoaded('receipts') ? $line->receipts->map(fn (Receipt $r) => [
                'id' => $r->id,
                'label' => $r->vendor ?? $r->original_name,
                'logo_url' => ReceiptController::logoUrl($r->vendor_domain),
                'filename' => $r->filename ?? $r->original_name,
                'state' => $r->match_state,
                'method' => $r->match_method,
                'confident' => $r->match_confident,
                'note' => $r->match_note,
                'numbered' => $r->numbered_at !== null || $r->numberPrefix() !== null,
                'numbered_by_ernte' => $r->numbered_at !== null && $r->numbered_from_path !== null,
            ])->values()->all() : [],
            'invoice' => $line->invoice ? [
                'filed' => $line->invoice->dropbox_file_id !== null,
                'id' => $line->invoice->id,
                'number' => $line->invoice->number,
                'client' => $line->invoice->client?->name,
                'total' => round($line->invoice->total_rappen / 100, 2),
            ] : null,
        ];
    }
}
