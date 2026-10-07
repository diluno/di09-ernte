<?php

namespace Tests\Fixtures;

/** Builds minimal camt.053 files for tests that need many small statements. */
class CamtXml
{
    public const IBAN = 'CH9300762011623852957';

    /**
     * @param  list<array{ref: string, amount: string, credit?: bool, text?: string, qrr?: string, name?: string}>  $entries
     */
    public static function statement(string $date, int $sequence, string $opening, string $closing, array $entries, string $iban = self::IBAN): string
    {
        $body = '';
        foreach ($entries as $e) {
            $credit = $e['credit'] ?? true;
            $remittance = isset($e['text']) ? "<Ustrd>{$e['text']}</Ustrd>" : '';
            $remittance .= isset($e['qrr']) ? "<Strd><CdtrRefInf><Ref>{$e['qrr']}</Ref></CdtrRefInf></Strd>" : '';
            $party = $credit ? 'Dbtr' : 'Cdtr';
            $name = $e['name'] ?? 'Some Party';
            $body .= '<Ntry>'
                ."<Amt Ccy=\"CHF\">{$e['amount']}</Amt>"
                .'<CdtDbtInd>'.($credit ? 'CRDT' : 'DBIT').'</CdtDbtInd>'
                .'<RvslInd>false</RvslInd><Sts>BOOK</Sts>'
                ."<BookgDt><Dt>{$date}</Dt></BookgDt><ValDt><Dt>{$date}</Dt></ValDt>"
                ."<AcctSvcrRef>{$e['ref']}</AcctSvcrRef>"
                .'<BkTxCd><Domn><Cd>PMNT</Cd><Fmly><Cd>RCDT</Cd><SubFmlyCd>DMCT</SubFmlyCd></Fmly></Domn></BkTxCd>'
                ."<NtryDtls><TxDtls><Amt Ccy=\"CHF\">{$e['amount']}</Amt>"
                ."<RltdPties><{$party}><Nm>{$name}</Nm></{$party}></RltdPties>"
                .($remittance !== '' ? "<RmtInf>{$remittance}</RmtInf>" : '')
                .'</TxDtls></NtryDtls>'
                .'<AddtlNtryInf>'.($credit ? 'Gutschrift' : 'Belastung').'</AddtlNtryInf>'
                .'</Ntry>';
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.04"><BkToCstmrStmt>'
            ."<GrpHdr><MsgId>MSG-{$sequence}</MsgId></GrpHdr>"
            ."<Stmt><Id>STMT-{$sequence}</Id><ElctrncSeqNb>{$sequence}</ElctrncSeqNb>"
            ."<FrToDt><FrDtTm>{$date}T00:00:00+02:00</FrDtTm><ToDtTm>{$date}T23:59:59+02:00</ToDtTm></FrToDt>"
            ."<Acct><Id><IBAN>{$iban}</IBAN></Id></Acct>"
            ."<Bal><Tp><CdOrPrtry><Cd>OPBD</Cd></CdOrPrtry></Tp><Amt Ccy=\"CHF\">{$opening}</Amt><CdtDbtInd>CRDT</CdtDbtInd></Bal>"
            ."<Bal><Tp><CdOrPrtry><Cd>CLBD</Cd></CdOrPrtry></Tp><Amt Ccy=\"CHF\">{$closing}</Amt><CdtDbtInd>CRDT</CdtDbtInd></Bal>"
            .$body
            .'</Stmt></BkToCstmrStmt></Document>';
    }
}
