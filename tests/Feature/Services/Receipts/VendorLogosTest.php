<?php

use App\Jobs\ExtractReceipt;
use App\Jobs\FetchVendorLogo;
use App\Models\BusinessProfile;
use App\Models\Receipt;
use App\Models\User;
use App\Services\Dropbox\DropboxClient;
use App\Services\Receipts\PdfText;
use App\Services\Receipts\ReceiptExtractor;
use App\Services\Receipts\VendorLogos;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    BusinessProfile::create(['name' => 'Ernte Test', 'country' => 'CH', 'default_currency' => 'CHF', 'default_vat_rate' => 8.10]);
    Storage::fake('local');
    Cache::flush();
    Http::preventStrayRequests();
});

/** VendorLogos with DNS answered from a table instead of the network. */
function logosResolving(array $hosts): VendorLogos
{
    $logos = new class extends VendorLogos
    {
        public array $hosts = [];

        protected function resolve(string $host): array
        {
            return $this->hosts[$host] ?? [];
        }
    };
    $logos->hosts = $hosts;
    app()->instance(VendorLogos::class, $logos);

    return $logos;
}

function pngBytes(int $size = 180, string $colour = 'red'): string
{
    $image = new Imagick;
    $image->newImage($size, $size, $colour);
    $image->setImageFormat('png');

    return $image->getImageBlob();
}

test('a domain is reduced to a plain public host name or refused', function (?string $in, ?string $out) {
    expect(VendorLogos::normalise($in))->toBe($out);
})->with([
    ['https://www.Hetzner.com/de/', 'hetzner.com'],
    ['digitec.ch', 'digitec.ch'],
    ['billing@postmarkapp.com', null],
    ['shop.example.co.uk/path?x=1', 'shop.example.co.uk'],
    ['localhost', null],
    ['intranet.local', null],
    ['192.168.1.10', null],
    ['http://169.254.169.254/latest', null],
    ['not a domain', null],
    ['', null],
    [null, null],
]);

test('the icon the home page declares is fetched, shrunk and stored as PNG', function () {
    $logos = logosResolving(['hetzner.com' => ['88.198.0.1'], 'cdn.hetzner.com' => ['88.198.0.2']]);
    Http::fake([
        'https://hetzner.com/' => Http::response('<html><head><link rel="icon" sizes="32x32" href="/small.png"><link rel="apple-touch-icon" href="//cdn.hetzner.com/touch.png"><link rel="mask-icon" href="/m.svg"></head></html>'),
        'https://cdn.hetzner.com/touch.png' => Http::response(pngBytes(180), 200, ['Content-Type' => 'image/png']),
    ]);

    expect($logos->fetch('hetzner.com'))->toBeTrue();

    $stored = new Imagick;
    $stored->readImageBlob(Storage::disk('local')->get('vendor-logos/hetzner.com.png'));
    expect([$stored->getImageWidth(), $stored->getImageHeight(), strtolower($stored->getImageFormat())])->toBe([64, 64, 'png']);
    expect($logos->has('hetzner.com'))->toBeTrue();
    expect($logos->wanted('hetzner.com'))->toBeFalse();
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'small.png') || str_contains($r->url(), '.svg'));
});

test('without a declared icon the conventional paths are tried; redirects are followed', function () {
    $logos = logosResolving(['digitec.ch' => ['93.184.216.34'], 'www.digitec.ch' => ['93.184.216.34']]);
    Http::fake([
        'https://digitec.ch/' => Http::response('', 301, ['Location' => 'https://www.digitec.ch/']),
        'https://www.digitec.ch/' => Http::response('<html><head></head></html>'),
        'https://digitec.ch/apple-touch-icon.png' => Http::response('nope', 404),
        'https://digitec.ch/favicon.ico' => Http::response(pngBytes(32), 200),
    ]);

    expect($logos->fetch('digitec.ch'))->toBeTrue();
});

test('hosts that are not on the public internet are never contacted', function () {
    $logos = logosResolving([
        'evil.com' => ['10.0.0.5'], 'half.com' => ['93.184.216.34', '127.0.0.1'], 'meta.com' => ['169.254.169.254'],
        'hop.com' => ['93.184.216.34'], 'inner.com' => ['192.168.1.1'],
    ]);
    Http::fake(['https://hop.com/*' => Http::response('', 302, ['Location' => 'https://inner.com/secret'])]);

    expect($logos->isPublicHost('evil.com'))->toBeFalse();
    expect($logos->isPublicHost('half.com'))->toBeFalse();
    expect($logos->isPublicHost('meta.com'))->toBeFalse();
    expect($logos->isPublicHost('unresolvable.com'))->toBeFalse();
    expect($logos->isPublicHost('127.0.0.1'))->toBeFalse();

    expect($logos->fetch('evil.com'))->toBeFalse();
    expect($logos->fetch('hop.com'))->toBeFalse(); // redirect into a private network is not followed
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'evil.com') || str_contains($r->url(), 'inner.com'));
});

test('a site that refuses the request falls back to the icon service, asked last', function () {
    $logos = logosResolving(['digitec.ch' => ['93.184.216.34'], 'icons.duckduckgo.com' => ['52.142.124.215']]);
    Http::fake([
        'https://digitec.ch/*' => Http::response('Access denied', 403),
        'https://icons.duckduckgo.com/ip3/digitec.ch.ico' => Http::response(pngBytes(48, 'blue')),
    ]);

    expect($logos->fetch('digitec.ch'))->toBeTrue();
    expect($logos->has('digitec.ch'))->toBeTrue();

    $urls = Http::recorded()->map(fn ($pair) => $pair[0]->url())->all();
    expect(end($urls))->toBe('https://icons.duckduckgo.com/ip3/digitec.ch.ico');
    expect($urls)->toContain('https://digitec.ch/favicon.ico');
});

test('the icon service is not asked when the vendor site has an icon', function () {
    $logos = logosResolving(['hetzner.com' => ['88.198.0.1'], 'icons.duckduckgo.com' => ['52.142.124.215']]);
    Http::fake([
        'https://hetzner.com/' => Http::response('<html></html>'),
        'https://hetzner.com/apple-touch-icon.png' => Http::response(pngBytes(180)),
    ]);

    expect($logos->fetch('hetzner.com'))->toBeTrue();
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'duckduckgo'));
});

test('something that is not an image is not stored, and the site is not asked again for a while', function () {
    $logos = logosResolving(['shop.ch' => ['93.184.216.34']]);
    Http::fake(['https://shop.ch/*' => Http::response('<html>not an image</html>')]);

    expect($logos->fetch('shop.ch'))->toBeFalse();
    expect($logos->has('shop.ch'))->toBeFalse();
    expect($logos->wanted('shop.ch'))->toBeFalse();
    Storage::disk('local')->assertMissing('vendor-logos/shop.ch.png');
});

test('reading a receipt stores the domain and queues the icon once', function () {
    logosResolving([]);
    Bus::fake([FetchVendorLogo::class]);
    Storage::disk('local')->put('receipts/a.pdf', '%PDF');
    $receipt = Receipt::create(['original_name' => 'a.pdf', 'content_hash' => hash('sha256', 'a'), 'original_mime' => 'application/pdf', 'size_bytes' => 4, 'local_path' => 'receipts/a.pdf']);
    $this->mock(ReceiptExtractor::class)->shouldReceive('read')->andReturn(ReceiptExtractor::normalise([
        'vendor' => 'Hetzner', 'vendor_domain' => 'https://www.hetzner.com/', 'document_date' => '2026-07-04', 'total' => '28.66', 'currency' => 'EUR',
        'amounts' => [], 'invoice_number' => null, 'payment_method' => 'card', 'confidence' => 'high',
    ]));

    (new ExtractReceipt($receipt->id))->handle(app(ReceiptExtractor::class), app(PdfText::class), app(DropboxClient::class));

    expect($receipt->fresh()->vendor_domain)->toBe('hetzner.com');
    Bus::assertDispatched(FetchVendorLogo::class, fn ($job) => $job->domain === 'hetzner.com');
});

test('the list carries the logo once it exists, and the logo is served only for stored domains', function () {
    $this->actingAs(User::factory()->create());
    Bus::fake();
    $receipt = Receipt::create(['original_name' => 'a.pdf', 'content_hash' => hash('sha256', 'a'), 'original_mime' => 'application/pdf', 'size_bytes' => 4,
        'vendor' => 'Hetzner', 'vendor_domain' => 'hetzner.com', 'target_year' => 2026, 'target_month' => 7]);

    $this->get('/receipts')->assertInertia(fn (Assert $p) => $p->where('receipts.data.0.logo_url', null)->where('receipts.data.0.vendor_domain', 'hetzner.com'));
    $this->get('/vendor-logos/hetzner.com')->assertNotFound();

    Storage::disk('local')->put('vendor-logos/hetzner.com.png', pngBytes(64));
    $this->get('/receipts')->assertInertia(fn (Assert $p) => $p->where('receipts.data.0.logo_url', '/vendor-logos/hetzner.com'));
    $this->get('/vendor-logos/hetzner.com')->assertOk()->assertHeader('Content-Type', 'image/png');
    $this->get('/vendor-logos/..%2F..%2F.env')->assertNotFound();

    // Correcting the website by hand fetches the new icon.
    $this->patch("/receipts/{$receipt->id}", ['vendor' => 'Hetzner', 'vendor_domain' => 'https://www.Hetzner.de/x', 'target_year' => 2026, 'target_month' => 7]);
    expect($receipt->fresh()->vendor_domain)->toBe('hetzner.de');
    Bus::assertDispatched(FetchVendorLogo::class, fn ($job) => $job->domain === 'hetzner.de');
});

test('a website set on one receipt applies to the vendor\'s other receipts, old and new', function () {
    $this->actingAs(User::factory()->create());
    logosResolving([]);
    Bus::fake([FetchVendorLogo::class]);
    $make = fn (string $vendor, ?string $domain = null) => Receipt::create(['original_name' => 'x.pdf', 'content_hash' => hash('sha256', uniqid()), 'original_mime' => 'application/pdf', 'size_bytes' => 4,
        'vendor' => $vendor, 'vendor_domain' => $domain, 'target_year' => 2026, 'target_month' => 7]);
    $edited = $make('DigitalOcean');
    $sibling = $make('digitalocean');
    $stale = $make('DigitalOcean', 'digitalocean.io');
    $own = $make('DigitalOcean', 'do.co');
    $other = $make('Hetzner');

    $this->patch("/receipts/{$edited->id}", ['vendor' => 'DigitalOcean', 'vendor_domain' => 'digitalocean.io', 'target_year' => 2026, 'target_month' => 7]);
    $this->patch("/receipts/{$edited->id}", ['vendor' => 'DigitalOcean', 'vendor_domain' => 'digitalocean.com', 'target_year' => 2026, 'target_month' => 7]);

    expect($sibling->fresh()->vendor_domain)->toBe('digitalocean.com'); // had none, then followed the correction
    expect($stale->fresh()->vendor_domain)->toBe('digitalocean.com');   // carried the value that was replaced
    expect($own->fresh()->vendor_domain)->toBe('do.co');                // a different, deliberate value is kept
    expect($other->fresh()->vendor_domain)->toBeNull();

    // A later receipt of the vendor whose document prints no website inherits it.
    Storage::disk('local')->put('receipts/n.pdf', '%PDF');
    $new = Receipt::create(['original_name' => 'n.pdf', 'content_hash' => hash('sha256', 'n'), 'original_mime' => 'application/pdf', 'size_bytes' => 4, 'local_path' => 'receipts/n.pdf']);
    $this->mock(ReceiptExtractor::class)->shouldReceive('read')->andReturn(ReceiptExtractor::normalise([
        'vendor' => 'DigitalOcean', 'vendor_domain' => null, 'document_date' => '2026-08-01', 'total' => '10.00', 'currency' => 'USD',
        'amounts' => [], 'invoice_number' => null, 'payment_method' => 'card', 'confidence' => 'high',
    ]));
    (new ExtractReceipt($new->id))->handle(app(ReceiptExtractor::class), app(PdfText::class), app(DropboxClient::class));

    expect($new->fresh()->vendor_domain)->toBeIn(['digitalocean.com', 'do.co']);
});
