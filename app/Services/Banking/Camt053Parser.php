<?php

namespace App\Services\Banking;

use DOMDocument;
use DOMElement;

/**
 * Reads a camt.053 bank statement (ISO 20022, Swiss Payment Standards) into plain arrays.
 * Pure: no database access. Elements are read by local name so versions 04 and 08 both work.
 */
class Camt053Parser
{
    private const NAMESPACES = [
        'urn:iso:std:iso:20022:tech:xsd:camt.053.001.04',
        'urn:iso:std:iso:20022:tech:xsd:camt.053.001.08',
    ];

    /**
     * @return list<array{
     *   account_iban: string, message_id: string, statement_ref: string, sequence_number: ?int,
     *   from_date: string, to_date: string, opening_balance_rappen: ?int, closing_balance_rappen: ?int,
     *   entries: list<array<string, mixed>>
     * }>
     */
    public function parse(string $xml): array
    {
        $doc = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $doc->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded || ! $doc->documentElement) {
            throw new CamtException('Not a readable XML file.');
        }
        if ($doc->doctype !== null) {
            throw new CamtException('XML with a DOCTYPE is not accepted.');
        }

        $root = $doc->documentElement;
        if ($root->localName !== 'Document' || ! in_array($root->namespaceURI, self::NAMESPACES, true)) {
            throw new CamtException('Not a camt.053 statement (version 04 or 08).');
        }

        $message = $this->child($root, 'BkToCstmrStmt');
        if (! $message) {
            throw new CamtException('Not a camt.053 statement (version 04 or 08).');
        }

        $messageId = (string) $this->text($message, 'GrpHdr', 'MsgId');
        $statements = [];
        foreach ($this->children($message, 'Stmt') as $stmt) {
            $statements[] = $this->statement($stmt, $messageId);
        }
        if ($statements === []) {
            throw new CamtException('The file contains no statement.');
        }

        return $statements;
    }

    /** "3891.6" -> 389160. String arithmetic only; floats would lose rappen. */
    public static function toRappen(string $amount): int
    {
        if (! preg_match('/^(\d+)(?:\.(\d+))?$/', trim($amount), $m)) {
            throw new CamtException("Unreadable amount: {$amount}");
        }
        $fraction = $m[2] ?? '';
        if (strlen($fraction) > 2 && rtrim(substr($fraction, 2), '0') !== '') {
            throw new CamtException("Amount with more than two decimals: {$amount}");
        }

        return (int) $m[1] * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }

    private function statement(DOMElement $stmt, string $messageId): array
    {
        $iban = $this->text($stmt, 'Acct', 'Id', 'IBAN');
        $ref = $this->text($stmt, 'Id');
        $from = $this->text($stmt, 'FrToDt', 'FrDtTm');
        $to = $this->text($stmt, 'FrToDt', 'ToDtTm');
        if (! $iban || ! $ref || ! $from || ! $to) {
            throw new CamtException('The statement lacks an account, id or period.');
        }

        $balances = [];
        foreach ($this->children($stmt, 'Bal') as $bal) {
            $code = $this->text($bal, 'Tp', 'CdOrPrtry', 'Cd');
            $amount = $this->text($bal, 'Amt');
            if ($code && $amount !== null) {
                $sign = $this->text($bal, 'CdtDbtInd') === 'DBIT' ? -1 : 1;
                $balances[$code] = $sign * self::toRappen($amount);
            }
        }

        $entries = [];
        foreach ($this->children($stmt, 'Ntry') as $index => $ntry) {
            // SPS 08 wraps the status code: <Sts><Cd>BOOK</Cd></Sts>.
            $status = $this->text($ntry, 'Sts', 'Cd') ?? $this->text($ntry, 'Sts');
            if ($status !== 'BOOK') {
                continue;
            }
            $entries[] = $this->entry($ntry, $index);
        }

        $sequence = $this->text($stmt, 'ElctrncSeqNb');

        return [
            'account_iban' => strtoupper(preg_replace('/\s+/', '', $iban)),
            'message_id' => $messageId,
            'statement_ref' => $ref,
            'sequence_number' => $sequence !== null ? (int) $sequence : null,
            'from_date' => substr($from, 0, 10),
            'to_date' => substr($to, 0, 10),
            'opening_balance_rappen' => $balances['OPBD'] ?? null,
            'closing_balance_rappen' => $balances['CLBD'] ?? null,
            'entries' => $entries,
        ];
    }

    private function entry(DOMElement $ntry, int $index): array
    {
        $bankRef = $this->text($ntry, 'AcctSvcrRef');
        $amount = $this->child($ntry, 'Amt');
        $bookedOn = $this->text($ntry, 'BookgDt', 'Dt') ?? substr((string) $this->text($ntry, 'BookgDt', 'DtTm'), 0, 10);
        if (! $bankRef || ! $amount || ! $bookedOn) {
            throw new CamtException('An entry lacks its bank reference, amount or booking date.');
        }

        $isCredit = $this->text($ntry, 'CdtDbtInd') === 'CRDT';
        $family = $this->text($ntry, 'BkTxCd', 'Domn', 'Fmly', 'Cd');
        $subFamily = $this->text($ntry, 'BkTxCd', 'Domn', 'Fmly', 'SubFmlyCd');

        $transactions = [];
        foreach ($this->children($ntry, 'NtryDtls') as $details) {
            foreach ($this->children($details, 'TxDtls') as $tx) {
                $transactions[] = $this->transaction($tx, $isCredit);
            }
        }
        $single = count($transactions) === 1 ? $transactions[0] : null;

        return [
            'bank_ref' => $bankRef,
            'entry_index' => $index,
            'booked_on' => $bookedOn,
            'value_on' => $this->text($ntry, 'ValDt', 'Dt'),
            'is_credit' => $isCredit,
            'amount_rappen' => self::toRappen($amount->textContent),
            'currency' => $amount->getAttribute('Ccy') ?: 'CHF',
            'is_reversal' => $this->text($ntry, 'RvslInd') === 'true',
            'is_fee' => $family === 'CHRG' || $subFamily === 'CHRG',
            'bank_tx_code' => $subFamily,
            'description' => $this->text($ntry, 'AddtlNtryInf'),
            'remittance_text' => $single['remittance_text'] ?? null,
            'creditor_reference' => $single['creditor_reference'] ?? null,
            'counterparty_name' => $single['counterparty_name'] ?? $this->text($ntry, 'CardTx', 'POI', 'Id', 'Id'),
            // Only a collective booking keeps its transactions; a single one is flattened above.
            'transactions' => count($transactions) > 1 ? $transactions : null,
        ];
    }

    private function transaction(DOMElement $tx, bool $isCredit): array
    {
        $amount = $this->child($tx, 'Amt');
        $remittance = $this->child($tx, 'RmtInf');

        $texts = [];
        $reference = null;
        if ($remittance) {
            foreach ($this->children($remittance, 'Ustrd') as $ustrd) {
                $texts[] = trim($ustrd->textContent);
            }
            foreach ($this->children($remittance, 'Strd') as $strd) {
                $reference ??= $this->text($strd, 'CdtrRefInf', 'Ref');
                foreach ($this->children($strd, 'AddtlRmtInf') as $extra) {
                    $texts[] = trim($extra->textContent);
                }
            }
        }

        $parties = $this->child($tx, 'RltdPties');
        $name = null;
        if ($parties) {
            foreach ($isCredit ? ['Dbtr', 'UltmtDbtr'] : ['Cdtr', 'UltmtCdtr'] as $role) {
                // SPS 08 nests the party: <Dbtr><Pty><Nm>…
                $name ??= $this->text($parties, $role, 'Nm') ?? $this->text($parties, $role, 'Pty', 'Nm');
            }
        }

        $text = trim(implode(' ', array_filter($texts, fn ($t) => $t !== '')));

        return [
            'amount_rappen' => $amount ? self::toRappen($amount->textContent) : null,
            'remittance_text' => $text !== '' ? $text : null,
            'creditor_reference' => $reference ? preg_replace('/\s+/', '', $reference) : null,
            'counterparty_name' => $name,
        ];
    }

    private function child(DOMElement $parent, string $name): ?DOMElement
    {
        foreach ($parent->childNodes as $node) {
            if ($node instanceof DOMElement && $node->localName === $name) {
                return $node;
            }
        }

        return null;
    }

    /** @return list<DOMElement> */
    private function children(DOMElement $parent, string $name): array
    {
        $found = [];
        foreach ($parent->childNodes as $node) {
            if ($node instanceof DOMElement && $node->localName === $name) {
                $found[] = $node;
            }
        }

        return $found;
    }

    /** Trimmed text at a path of child element names; null when missing or empty. */
    private function text(DOMElement $parent, string ...$path): ?string
    {
        $node = $parent;
        foreach ($path as $name) {
            $node = $this->child($node, $name);
            if (! $node) {
                return null;
            }
        }
        $text = trim($node->textContent);

        return $text !== '' ? $text : null;
    }
}
