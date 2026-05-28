<?php

namespace App\Services\Harvest;

class HarvestData
{
    public function __construct(
        public array $clients = [],
        public array $contacts = [],
        public array $projects = [],
        public array $invoices = [],
        public array $estimates = [],
    ) {}
}
