<?php

namespace App\Services\Receipts;

use App\Models\BusinessProfile;
use App\Models\MonthList;
use App\Models\Receipt;
use App\Models\Statement;
use App\Models\StatementLine;
use App\Services\Banking\StatementPositions;
use App\Services\Banking\VisecaImporter;
use App\Services\Dropbox\DropboxClient;
use App\Services\Dropbox\DropboxConflict;
use App\Services\Dropbox\DropboxNotFound;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\View;
use Spatie\Browsershot\Browsershot;

/**
 * The "Belegliste": one PDF per month folder that tells the accountant, row by row, which
 * file belongs to which bank or card entry, what needs no document, what is missing, and
 * what Sam noted. It is the only file ernte ever replaces, and only its own.
 */
class MonthListWriter
{
    private const MONTHS = [1 => 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];

    public function __construct(private DropboxClient $dropbox, private StatementPositions $positions) {}

    /**
     * Write or replace the list of a month in Dropbox.
     *
     * @throws \DomainException when the month is not ready or a foreign file has the name
     */
    public function write(int $year, int $month): MonthList
    {
        if (! $this->dropbox->isConnected()) {
            throw new \DomainException('Dropbox is not connected.');
        }
        if (! $this->positions->bankMonthComplete($year, $month)) {
            throw new \DomainException(sprintf('The bank statements of %02d/%d are not complete yet, so its numbers are not final.', $month, $year));
        }

        $pdf = $this->render($this->data($year, $month));
        $list = MonthList::where('year', $year)->where('month', $month)->first();

        $entry = null;
        if ($list) {
            try {
                $entry = $this->dropbox->replace($list->dropbox_file_id, $pdf);
            } catch (DropboxNotFound) {
                // Someone deleted the old list: write a new one.
            }
        }
        if (! $entry) {
            $root = $this->dropbox->root();
            $this->dropbox->createFolder(ReceiptPaths::quarterFolder($root, $year, $month));
            $folder = ReceiptPaths::monthFolder($root, $year, $month);
            $this->dropbox->createFolder($folder);
            $name = sprintf('_Belegliste %d-%02d.pdf', $year, $month);
            try {
                $entry = $this->dropbox->upload("{$folder}/{$name}", $pdf);
            } catch (DropboxConflict) {
                throw new \DomainException("A file named \"{$name}\" that ernte did not write already exists in the folder. Remove or rename it first.");
            }
        }

        return MonthList::updateOrCreate(['year' => $year, 'month' => $month], [
            'dropbox_file_id' => $entry['id'], 'dropbox_path' => $entry['path'], 'written_at' => now(),
        ]);
    }

    /** Everything the list shows, in German, ready for the template. */
    public function data(int $year, int $month): array
    {
        $with = ['invoice', 'receipts' => fn ($q) => $q->where('match_state', 'matched')->orderBy('id')];

        $sections = [[
            'title' => 'Bankkonto',
            'meta' => null,
            'card' => false,
            'rows' => $this->positions->bankMonth($year, $month)->load($with)->map(fn (StatementLine $l) => $this->row($l, false))->all(),
        ]];

        $bills = Statement::where('source', VisecaImporter::SOURCE)->whereNotNull('bank_line_id')
            ->whereHas('bankLine', fn ($q) => $q->whereYear('booked_on', $year)->whereMonth('booked_on', $month))
            ->with('bankLine')->get();
        foreach ($bills as $bill) {
            $sections[] = [
                'title' => 'Kreditkarte',
                'meta' => 'Abrechnung, belastet am '.$bill->bankLine->booked_on->format('d.m.Y').' mit CHF '.self::chf($bill->charges_rappen).' · Ordner «Kreditkarte»',
                'card' => true,
                'rows' => $this->positions->bill($bill)->load($with)->map(fn (StatementLine $l) => $this->row($l, true))->all(),
            ];
        }

        $rows = collect($sections)->flatMap(fn ($s) => $s['rows']);

        return [
            'title' => 'Belegliste '.self::MONTHS[$month].' '.$year,
            'company' => BusinessProfile::current()->name,
            'written' => now()->timezone('Europe/Zurich')->format('d.m.Y H:i'),
            'sections' => $sections,
            'count' => $rows->count(),
            'missing' => $rows->where('status', 'missing')->count(),
        ];
    }

    public function render(array $data): string
    {
        $shot = Browsershot::html(View::make('documents.belegliste', $data)->render())
            ->format('A4')->margins(14, 12, 14, 12)->showBackground()->noSandbox();
        if ($path = config('services.browsershot.chrome_path')) {
            $shot->setChromePath($path);
        }

        return $shot->pdf();
    }

    private function row(StatementLine $line, bool $card): array
    {
        $documents = [];
        $status = 'ok';
        $notes = array_filter([$line->note]);

        foreach ($line->receipts as $receipt) {
            /** @var Receipt $receipt */
            $documents[] = $receipt->filename ?? $receipt->original_name;
            if ($receipt->note) {
                $notes[] = $receipt->note;
            }
        }

        if ($line->is_credit && ! $card) {
            if ($line->invoice && $line->match_state === 'matched') {
                $documents[] = $line->invoice->dropbox_path
                    ? basename($line->invoice->dropbox_path)
                    : "Rechnung {$line->invoice->number} (PDF noch nicht abgelegt)";
            } elseif ($line->match_state === 'ignored') {
                $documents[] = 'keine Rechnungszahlung';
            } elseif ($documents === []) {
                $documents[] = 'Gutschrift ohne zugeordnete Rechnung';
                $status = 'missing';
            }
        } elseif ($line->is_credit) {
            $documents = $documents ?: ['Gutschrift, nicht nummeriert'];
        } elseif ($documents === []) {
            if ($line->is_fee) {
                $documents[] = 'Bankgebühr, kein Beleg';
            } elseif ($line->no_receipt) {
                $documents[] = 'kein Beleg nötig';
            } else {
                $documents[] = 'Beleg fehlt';
                $status = 'missing';
            }
        }

        $at = $card ? ($line->transacted_at ?? $line->booked_on) : $line->booked_on;

        return [
            'number' => $line->number !== null ? StatementPositions::prefix($line->number) : '–',
            'date' => Carbon::parse($at)->format('d.m.'),
            'text' => trim(($line->counterparty_name ?? $line->description ?? '').($line->remittance_text ? ' · '.$line->remittance_text : '')),
            'amount' => (($card ? $line->is_credit : ! $line->is_credit) ? '−' : '').self::chf($line->amount_rappen),
            'original' => $card && $line->original_currency && $line->original_currency !== 'CHF'
                ? self::chf($line->original_amount_minor).' '.$line->original_currency : null,
            'documents' => $documents,
            'note' => implode(' · ', $notes),
            'status' => $status,
        ];
    }

    private static function chf(int $rappen): string
    {
        return number_format($rappen / 100, 2, '.', "'");
    }
}
