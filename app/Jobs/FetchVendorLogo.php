<?php

namespace App\Jobs;

use App\Services\Receipts\VendorLogos;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class FetchVendorLogo implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public string $domain) {}

    public function uniqueId(): string
    {
        return $this->domain;
    }

    public function handle(VendorLogos $logos): void
    {
        $logos->fetch($this->domain);
    }
}
