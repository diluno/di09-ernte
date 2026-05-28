<?php

namespace App\Services\Harvest;

class ImportSummary
{
    /** @param string[] $warnings */
    public function __construct(
        public int $clients = 0,
        public int $projects = 0,
        public int $invoices = 0,
        public int $estimates = 0,
        public array $warnings = [],
    ) {}
}
