<?php

namespace App\Services\Receipts;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Imagick;

/**
 * A vendor's logo is its website's icon, fetched once and kept on the server. The
 * vendor's own site is asked first; an icon service only when that yields nothing.
 *
 * The domain comes from a document, i.e. from outside: every host is checked to be a
 * public internet address before any request, redirects are followed by hand with the
 * same check, and whatever comes back is re-encoded as a small PNG before it is stored.
 */
class VendorLogos
{
    private const MAX_BYTES = 400_000;

    private const MAX_REDIRECTS = 3;

    private const SIZE = 64;

    public const FALLBACK_SERVICE = 'https://icons.duckduckgo.com/ip3';

    /** "https://www.Hetzner.com/de/" -> "hetzner.com"; null when it is not a plain public domain. */
    public static function normalise(?string $value): ?string
    {
        $host = strtolower(trim((string) $value));
        $host = preg_replace('#^[a-z]+://#', '', $host);
        $host = preg_replace('#[/?\#:].*$#', '', $host);
        $host = preg_replace('/^www\./', '', $host);

        if (strlen($host) > 100 || ! preg_match('/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,24}$/', $host)) {
            return null;
        }
        if (preg_match('/\.(local|localhost|internal|test|example|invalid|lan|home|corp)$/', $host)) {
            return null;
        }

        return $host;
    }

    public function path(string $domain): string
    {
        return "vendor-logos/{$domain}.png";
    }

    public function has(?string $domain): bool
    {
        return $domain !== null && Storage::disk('local')->exists($this->path($domain));
    }

    /** Whether a fetch is worth queueing: no logo yet, and not recently tried in vain. */
    public function wanted(?string $domain): bool
    {
        return $domain !== null && self::normalise($domain) === $domain && ! $this->has($domain) && ! Cache::has("vendor-logo-miss:{$domain}");
    }

    /** Fetch and store the icon. Returns false when the site offers none ernte can use. */
    public function fetch(string $domain): bool
    {
        if (self::normalise($domain) !== $domain) {
            return false;
        }
        if ($this->has($domain)) {
            return true;
        }

        foreach ($this->candidates($domain) as $url) {
            $bytes = $this->get($url);
            $png = $bytes !== null ? $this->toPng($bytes) : null;
            if ($png !== null) {
                Storage::disk('local')->put($this->path($domain), $png);

                return true;
            }
        }

        // Do not ask the same site again for a week.
        Cache::put("vendor-logo-miss:{$domain}", true, now()->addDays(7));

        return false;
    }

    /** Icons the site's home page declares, largest first, then the two conventional paths. */
    private function candidates(string $domain): array
    {
        $urls = [];
        $html = $this->get("https://{$domain}/", html: true);
        if ($html !== null && preg_match_all('/<link\b[^>]*>/i', substr($html, 0, 200_000), $tags)) {
            $found = [];
            foreach ($tags[0] as $tag) {
                if (! preg_match('/\brel\s*=\s*["\']?([^"\'>]+)/i', $tag, $rel) || ! preg_match('/\bhref\s*=\s*["\']?([^"\'\s>]+)/i', $tag, $href)) {
                    continue;
                }
                $rel = strtolower($rel[1]);
                if (! str_contains($rel, 'icon') || str_contains($rel, 'mask')) {
                    continue;
                }
                $size = preg_match('/\bsizes\s*=\s*["\']?(\d+)x/i', $tag, $s) ? (int) $s[1] : (str_contains($rel, 'apple') ? 180 : 16);
                if ($absolute = $this->absolute(html_entity_decode($href[1]), $domain)) {
                    $found[] = [$size, $absolute];
                }
            }
            usort($found, fn ($a, $b) => $b[0] <=> $a[0]);
            $urls = array_column(array_slice($found, 0, 4), 1);
        }

        return array_values(array_unique([
            ...$urls, "https://{$domain}/apple-touch-icon.png", "https://{$domain}/favicon.ico",
            // Last resort for sites that refuse anything but a browser (decided by Sam
            // 2026-10-07): a public icon service. It learns the vendor's domain, nothing else.
            self::FALLBACK_SERVICE."/{$domain}.ico",
        ]));
    }

    private function absolute(string $href, string $domain): ?string
    {
        if (str_starts_with($href, 'data:') || str_ends_with(strtolower(strtok($href, '?')), '.svg')) {
            return null; // SVG can carry scripts; only raster icons are taken.
        }
        if (str_starts_with($href, '//')) {
            return "https:{$href}";
        }
        if (preg_match('#^https://#i', $href)) {
            return $href;
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $href)) {
            return null;
        }

        return "https://{$domain}/".ltrim($href, '/');
    }

    /** GET over https from a public host only, following a few redirects under the same rule. */
    private function get(string $url, bool $html = false): ?string
    {
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $parts = parse_url($url);
            if (($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['port'])
                || ! $this->isPublicHost($parts['host'])) {
                return null;
            }

            try {
                $response = Http::withOptions(['allow_redirects' => false])
                    ->withHeaders(['User-Agent' => 'ernte/1.0 (vendor icon fetch)', 'Accept' => $html ? 'text/html' : 'image/*'])
                    ->timeout(6)->connectTimeout(4)->get($url);
            } catch (\Throwable) {
                return null;
            }

            if ($response->redirect()) {
                $location = (string) $response->header('Location');
                $url = preg_match('#^https?://#i', $location) ? $location : "https://{$parts['host']}/".ltrim($location, '/');

                continue;
            }
            $body = $response->body();
            if (! $response->successful() || $body === '' || strlen($body) > ($html ? 2_000_000 : self::MAX_BYTES)) {
                return null;
            }

            return $body;
        }

        return null;
    }

    /** True only if the name resolves, and every address it resolves to is on the public internet. */
    public function isPublicHost(string $host): bool
    {
        $host = strtolower($host);
        if (filter_var($host, FILTER_VALIDATE_IP) || self::normalise($host) === null) {
            return false;
        }
        $addresses = $this->resolve($host);
        if ($addresses === []) {
            return false;
        }
        foreach ($addresses as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    protected function resolve(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];

        return array_values(array_filter(array_map(fn ($r) => $r['ip'] ?? $r['ipv6'] ?? null, $records)));
    }

    /** Re-encode whatever image arrived as a square-ish PNG; null if it is not an image. */
    private function toPng(string $bytes): ?string
    {
        try {
            $image = new Imagick;
            // ICO files hold several sizes; the hint makes Imagick decode them as icons.
            if (str_starts_with($bytes, "\x00\x00\x01\x00")) {
                $image->setFormat('ico');
            }
            $image->readImageBlob($bytes);

            // Take the largest frame.
            $best = 0;
            $area = 0;
            foreach ($image as $index => $frame) {
                if ($frame->getImageWidth() * $frame->getImageHeight() > $area) {
                    $area = $frame->getImageWidth() * $frame->getImageHeight();
                    $best = $index;
                }
            }
            $image->setIteratorIndex($best);
            $frame = $image->getImage();
            if ($frame->getImageWidth() < 8 || $frame->getImageWidth() > 4096 || $frame->getImageHeight() > 4096) {
                return null;
            }
            if (max($frame->getImageWidth(), $frame->getImageHeight()) > self::SIZE) {
                $frame->thumbnailImage(self::SIZE, self::SIZE, true);
            }
            $frame->stripImage();
            $frame->setImageFormat('png');

            return $frame->getImageBlob();
        } catch (\Throwable) {
            return null;
        }
    }
}
