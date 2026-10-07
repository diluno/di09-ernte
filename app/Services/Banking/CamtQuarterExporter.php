<?php

namespace App\Services\Banking;

use App\Models\QuarterFile;
use App\Models\Statement;
use App\Services\Dropbox\DropboxClient;
use App\Services\Dropbox\DropboxConflict;
use App\Services\Dropbox\DropboxNotFound;
use App\Services\Receipts\ReceiptPaths;
use App\Support\Quarter;
use DOMDocument;
use DOMElement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * One camt.053 file for exactly one quarter, for the accountant. E-banking only hands out
 * daily files; this joins the bank's own entries from those files into a single statement.
 * Entries are copied node for node, never rewritten; only the statement's frame (period,
 * balances, totals, ids) is set for the quarter.
 */
class CamtQuarterExporter
{
    public const KIND = 'camt053';

    public function __construct(private DropboxClient $dropbox, private Camt053Parser $parser, private StatementPositions $positions) {}

    /** Why the quarter cannot be exported yet, or null when it can. */
    public function blocker(Quarter $quarter): ?string
    {
        try {
            $this->statements($quarter);

            return null;
        } catch (\DomainException $e) {
            return $e->getMessage();
        }
    }

    /** Write (or replace) the quarter's camt file in its Dropbox folder. */
    public function write(Quarter $quarter): QuarterFile
    {
        if (! $this->dropbox->isConnected()) {
            throw new \DomainException('Dropbox is not connected.');
        }
        $xml = $this->build($quarter);

        $file = QuarterFile::where(['year' => $quarter->year, 'quarter' => $quarter->number, 'kind' => self::KIND])->first();
        $entry = null;
        if ($file) {
            try {
                $entry = $this->dropbox->replace($file->dropbox_file_id, $xml);
            } catch (DropboxNotFound) {
                // Deleted in Dropbox: write it anew.
            }
        }
        if (! $entry) {
            $folder = ReceiptPaths::quarterFolder($this->dropbox->root(), $quarter->year, $quarter->months()[0]);
            $this->dropbox->createFolder($folder);
            $name = "_camt053_{$quarter->year}_Q{$quarter->number}.xml";
            try {
                $entry = $this->dropbox->upload("{$folder}/{$name}", $xml);
            } catch (DropboxConflict) {
                throw new \DomainException("A file named \"{$name}\" that ernte did not write already exists in the folder. Remove or rename it first.");
            }
        }

        return QuarterFile::updateOrCreate(
            ['year' => $quarter->year, 'quarter' => $quarter->number, 'kind' => self::KIND],
            ['dropbox_file_id' => $entry['id'], 'dropbox_path' => $entry['path'], 'written_at' => now()],
        );
    }

    /** The merged camt.053 document as XML. */
    public function build(Quarter $quarter): string
    {
        $statements = $this->statements($quarter);
        $start = Carbon::create($quarter->year, $quarter->months()[0], 1)->startOfDay();
        $end = Carbon::create($quarter->year, $quarter->months()[2], 1)->endOfMonth();

        $first = $statements->first();
        $doc = $this->load($first);
        $stmt = $this->stmt($doc, $first);
        $ns = $doc->documentElement->namespaceURI;
        $root = $this->child($doc->documentElement, 'BkToCstmrStmt');

        // Drop any other statement of the template file, and the template's own entries.
        foreach ($this->children($root, 'Stmt') as $other) {
            if (! $other->isSameNode($stmt)) {
                $root->removeChild($other);
            }
        }
        foreach ($this->children($stmt, 'Ntry') as $entry) {
            $stmt->removeChild($entry);
        }
        $tail = $this->child($stmt, 'AddtlStmtInf');

        $count = 0;
        $credits = $debits = 0;
        $creditCount = $debitCount = 0;
        foreach ($statements as $statement) {
            $source = $statement->is($first) ? null : $this->load($statement);
            // The first statement's entries were detached above; read them from a fresh copy.
            $from = $this->stmt($source ?? $this->load($first), $statement);
            foreach ($this->children($from, 'Ntry') as $entry) {
                // Booked entries only, as on import; a statement holds nothing else in practice.
                if (($this->text($entry, 'Sts', 'Cd') ?? $this->text($entry, 'Sts')) !== 'BOOK') {
                    continue;
                }
                $stmt->insertBefore($doc->importNode($entry, true), $tail);
                $amount = Camt053Parser::toRappen($this->child($entry, 'Amt')->textContent);
                $count++;
                if ($this->text($entry, 'CdtDbtInd') === 'CRDT') {
                    $credits += $amount;
                    $creditCount++;
                } else {
                    $debits += $amount;
                    $debitCount++;
                }
            }
        }

        $stamp = now()->timezone('Europe/Zurich');
        $offset = $stamp->format('P');
        $this->set($root, ['GrpHdr', 'MsgId'], "ERNTE-{$quarter->year}Q{$quarter->number}-".$stamp->format('YmdHis'));
        $this->set($root, ['GrpHdr', 'CreDtTm'], $stamp->format('Y-m-d\TH:i:s').$offset);
        $this->set($root, ['GrpHdr', 'MsgPgntn', 'PgNb'], '1');
        $this->set($root, ['GrpHdr', 'MsgPgntn', 'LastPgInd'], 'true');
        $this->set($stmt, ['Id'], "{$quarter->year}-Q{$quarter->number}");
        $this->set($stmt, ['ElctrncSeqNb'], (string) $quarter->number);
        $this->set($stmt, ['CreDtTm'], $stamp->format('Y-m-d\TH:i:s').$offset);
        $this->set($stmt, ['FrToDt', 'FrDtTm'], $start->format('Y-m-d\T00:00:00').$offset);
        $this->set($stmt, ['FrToDt', 'ToDtTm'], $end->format('Y-m-d\T23:59:59').$offset);

        $opening = $first->opening_balance_rappen;
        $closing = $statements->last()->closing_balance_rappen;
        foreach ($this->children($stmt, 'Bal') as $bal) {
            $code = $this->text($bal, 'Tp', 'CdOrPrtry', 'Cd');
            $value = $code === 'OPBD' ? $opening : $closing;
            $this->child($bal, 'Amt')->textContent = self::decimal(abs($value));
            $this->set($bal, ['CdtDbtInd'], $value < 0 ? 'DBIT' : 'CRDT');
            $this->set($bal, ['Dt', 'Dt'], ($code === 'OPBD' ? $start : $end)->format('Y-m-d'));
        }

        if ($summary = $this->child($stmt, 'TxsSummry')) {
            $net = $credits - $debits;
            $this->set($summary, ['TtlNtries', 'NbOfNtries'], (string) $count);
            $this->set($summary, ['TtlNtries', 'TtlNetNtry', 'Amt'], self::decimal(abs($net)));
            $this->set($summary, ['TtlNtries', 'TtlNetNtry', 'CdtDbtInd'], $net < 0 ? 'DBIT' : 'CRDT');
            $this->set($summary, ['TtlCdtNtries', 'NbOfNtries'], (string) $creditCount);
            $this->set($summary, ['TtlCdtNtries', 'Sum'], self::decimal($credits));
            $this->set($summary, ['TtlDbtNtries', 'NbOfNtries'], (string) $debitCount);
            $this->set($summary, ['TtlDbtNtries', 'Sum'], self::decimal($debits));
        }

        $xml = $doc->saveXML();
        $this->verify($xml, $count, $opening, $closing);

        return $xml;
    }

    /**
     * The quarter's statements in order, or the reason they cannot be merged.
     *
     * @return Collection<int, Statement>
     */
    private function statements(Quarter $quarter): Collection
    {
        $start = Carbon::create($quarter->year, $quarter->months()[0], 1)->startOfDay();
        $end = Carbon::create($quarter->year, $quarter->months()[2], 1)->endOfMonth();

        $statements = Statement::where('source', StatementImporter::SOURCE)
            ->whereDate('to_date', '>=', $start)->whereDate('to_date', '<=', $end)
            ->orderBy('to_date')->orderBy('sequence_number')->get();
        if ($statements->isEmpty()) {
            throw new \DomainException("No bank statements are imported for {$quarter->label()}.");
        }
        $previous = null;
        foreach ($statements as $statement) {
            if ($previous && $previous->closing_balance_rappen !== $statement->opening_balance_rappen) {
                throw new \DomainException("Statements are missing between {$previous->to_date->format('d.m.Y')} and {$statement->from_date->format('d.m.Y')}.");
            }
            $previous = $statement;
        }
        foreach ($quarter->months() as $month) {
            if (! $this->positions->bankMonthComplete($quarter->year, $month)) {
                throw new \DomainException("{$quarter->label()} is not complete yet: import the statements up to the first days of the following quarter.");
            }
        }

        $missing = $statements->filter(fn (Statement $s) => ! $s->raw_path || ! Storage::disk('local')->exists($s->raw_path))->count();
        if ($missing > 0) {
            throw new \DomainException("The bank's original files are not stored for {$missing} of {$statements->count()} days of {$quarter->label()}. Upload that quarter's camt files once more; nothing is imported twice.");
        }

        return $statements;
    }

    /** The result must read back as one statement with every entry and connecting balances. */
    private function verify(string $xml, int $count, int $opening, int $closing): void
    {
        $parsed = $this->parser->parse($xml);
        $statement = $parsed[0];
        $sum = 0;
        foreach ($statement['entries'] as $entry) {
            $sum += $entry['is_credit'] ? $entry['amount_rappen'] : -$entry['amount_rappen'];
        }
        if (count($parsed) !== 1 || count($statement['entries']) !== $count
            || $statement['opening_balance_rappen'] !== $opening || $statement['closing_balance_rappen'] !== $closing
            || $opening + $sum !== $closing) {
            throw new \DomainException('The merged file does not add up (entries or balances differ from the daily files), so it was not written.');
        }
    }

    private function load(Statement $statement): DOMDocument
    {
        $doc = new DOMDocument;
        $doc->preserveWhiteSpace = true;
        $doc->loadXML(Storage::disk('local')->get($statement->raw_path), LIBXML_NONET);

        return $doc;
    }

    private function stmt(DOMDocument $doc, Statement $statement): DOMElement
    {
        foreach ($this->children($this->child($doc->documentElement, 'BkToCstmrStmt'), 'Stmt') as $stmt) {
            if ($this->text($stmt, 'Id') === $statement->statement_ref) {
                return $stmt;
            }
        }

        throw new \DomainException("The stored file for {$statement->to_date->format('d.m.Y')} does not contain its statement.");
    }

    private function set(DOMElement $parent, array $path, string $value): void
    {
        $node = $parent;
        foreach ($path as $name) {
            $node = $this->child($node, $name);
            if (! $node) {
                return; // optional element the bank did not send: leave it out
            }
        }
        $node->textContent = $value;
    }

    private function child(?DOMElement $parent, string $name): ?DOMElement
    {
        foreach ($parent?->childNodes ?? [] as $node) {
            if ($node instanceof DOMElement && $node->localName === $name) {
                return $node;
            }
        }

        return null;
    }

    /** @return list<DOMElement> */
    private function children(?DOMElement $parent, string $name): array
    {
        $found = [];
        foreach ($parent?->childNodes ?? [] as $node) {
            if ($node instanceof DOMElement && $node->localName === $name) {
                $found[] = $node;
            }
        }

        return $found;
    }

    private function text(DOMElement $parent, string ...$path): ?string
    {
        $node = $parent;
        foreach ($path as $name) {
            $node = $this->child($node, $name);
            if (! $node) {
                return null;
            }
        }

        return trim($node->textContent);
    }

    private static function decimal(int $rappen): string
    {
        return intdiv($rappen, 100).'.'.str_pad((string) ($rappen % 100), 2, '0', STR_PAD_LEFT);
    }
}
