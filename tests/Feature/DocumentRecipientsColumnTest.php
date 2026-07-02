<?php
// tests/Feature/DocumentRecipientsColumnTest.php
use App\Models\Client;
use App\Models\Contact;
use App\Models\Invoice;

it('casts recipients to an array', function () {
    $invoice = Invoice::factory()->create(['recipients' => [['name' => 'A', 'email' => 'a@x.ch']]]);
    expect($invoice->fresh()->recipients)->toBe([['name' => 'A', 'email' => 'a@x.ch']]);
});
