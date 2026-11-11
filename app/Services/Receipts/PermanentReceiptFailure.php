<?php

namespace App\Services\Receipts;

/** A failure that trying again cannot fix: a missing API key, a refusal, a file that is not there. */
class PermanentReceiptFailure extends \RuntimeException {}
