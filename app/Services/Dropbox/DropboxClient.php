<?php

namespace App\Services\Dropbox;

use App\Models\BusinessProfile;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * The only door to Dropbox. The OAuth token can reach the whole account, so every path
 * is checked against the configured receipts root before a request is made, and there is
 * deliberately no delete and no overwrite.
 */
class DropboxClient
{
    public const SCOPES = 'account_info.read files.metadata.read files.content.read files.content.write';

    private const API = 'https://api.dropboxapi.com';

    private const CONTENT = 'https://content.dropboxapi.com';

    private const TOKEN_CACHE_KEY = 'dropbox.access_token';

    public function isConfigured(): bool
    {
        return filled(config('services.dropbox.app_key')) && filled(config('services.dropbox.app_secret'));
    }

    public function isConnected(): bool
    {
        return $this->isConfigured() && filled(BusinessProfile::current()->dropbox_refresh_token);
    }

    /** The folder everything must stay inside, e.g. "/Diluno/Receipts". */
    public function root(): string
    {
        $root = '/'.trim((string) config('services.dropbox.receipts_root'), '/');
        if ($root === '/') {
            throw new DropboxException('DROPBOX_RECEIPTS_ROOT is not set; refusing to work on the whole Dropbox.');
        }

        return $root;
    }

    // ── OAuth ──

    public function authorizeUrl(string $redirectUri, string $state): string
    {
        return 'https://www.dropbox.com/oauth2/authorize?'.http_build_query([
            'client_id' => config('services.dropbox.app_key'),
            'response_type' => 'code',
            'token_access_type' => 'offline',
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'scope' => self::SCOPES,
        ]);
    }

    /** Exchange the authorization code, store the refresh token, and return the account label. */
    public function connect(string $code, string $redirectUri): string
    {
        $response = $this->tokenRequest([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
        ]);
        $refreshToken = $response->json('refresh_token');
        if (! $refreshToken) {
            throw new DropboxException('Dropbox returned no refresh token.');
        }

        $this->rememberAccessToken($response);
        $account = Http::withToken($response->json('access_token'))
            ->withBody('null', 'application/json')
            ->post(self::API.'/2/users/get_current_account');
        $label = $account->json('email') ?? $account->json('name.display_name') ?? 'Dropbox account';

        BusinessProfile::current()->update([
            'dropbox_refresh_token' => $refreshToken,
            'dropbox_account_label' => $label,
            'dropbox_connected_at' => now(),
        ]);

        return $label;
    }

    /** Revoke the token at Dropbox (best effort) and forget the connection. */
    public function disconnect(): void
    {
        try {
            if ($this->isConnected()) {
                Http::withToken($this->accessToken())->withBody('null', 'application/json')->post(self::API.'/2/auth/token/revoke');
            }
        } catch (\Throwable) {
            // The local connection is removed regardless; a stale grant can be removed in Dropbox's own settings.
        }

        Cache::forget(self::TOKEN_CACHE_KEY);
        BusinessProfile::current()->update([
            'dropbox_refresh_token' => null, 'dropbox_account_label' => null, 'dropbox_connected_at' => null,
        ]);
    }

    // ── Files ──

    /**
     * @return array{id: ?string, name: string, path: string, is_folder: bool}
     */
    public function metadata(string $pathOrId): array
    {
        $isId = str_starts_with($pathOrId, 'id:');
        $response = $this->rpc('/2/files/get_metadata', ['path' => $isId ? $pathOrId : $this->guard($pathOrId)]);
        $entry = $this->entry($response->json());
        // An ID can point anywhere in the account; its real location decides.
        $this->guard($entry['path']);

        return $entry;
    }

    public function exists(string $path): bool
    {
        try {
            $this->metadata($path);

            return true;
        } catch (DropboxNotFound) {
            return false;
        }
    }

    /**
     * The direct children of a folder.
     *
     * @return list<array{id: ?string, name: string, path: string, is_folder: bool}>
     */
    public function listFolder(string $path): array
    {
        $response = $this->rpc('/2/files/list_folder', ['path' => $this->guard($path), 'recursive' => false, 'limit' => 500]);
        $entries = $response->json('entries', []);
        while ($response->json('has_more')) {
            $response = $this->rpc('/2/files/list_folder/continue', ['cursor' => $response->json('cursor')]);
            $entries = array_merge($entries, $response->json('entries', []));
        }

        return array_map(fn (array $entry) => $this->entry($entry), $entries);
    }

    /** Create a folder; an existing one is fine. */
    public function createFolder(string $path): void
    {
        try {
            $this->rpc('/2/files/create_folder_v2', ['path' => $this->guard($path), 'autorename' => false]);
        } catch (DropboxConflict) {
            // Already there.
        }
    }

    /**
     * Add a new file. Never overwrites: an existing name throws DropboxConflict.
     *
     * @return array{id: ?string, name: string, path: string, is_folder: bool}
     */
    public function upload(string $path, string $contents): array
    {
        $arg = ['path' => $this->guard($path), 'mode' => 'add', 'autorename' => false, 'mute' => true];

        $response = $this->send(fn (PendingRequest $http) => $http
            ->withHeaders(['Dropbox-API-Arg' => $this->headerJson($arg)])
            ->withBody($contents, 'application/octet-stream')
            ->post(self::CONTENT.'/2/files/upload'));

        return $this->entry($response->json() + ['.tag' => 'file']);
    }

    /**
     * Move or rename a file by ID. Its current location is looked up first, because Sam or
     * the accountant may have moved it since ernte last saw it.
     *
     * @return array{id: ?string, name: string, path: string, is_folder: bool}
     */
    public function move(string $fileId, string $toPath): array
    {
        $this->metadata($fileId);
        $response = $this->rpc('/2/files/move_v2', [
            'from_path' => $fileId, 'to_path' => $this->guard($toPath), 'autorename' => false,
        ]);

        return $this->entry($response->json('metadata'));
    }

    /**
     * Copy a file to a new place. Never overwrites: an existing name throws DropboxConflict.
     *
     * @return array{id: ?string, name: string, path: string, is_folder: bool}
     */
    public function copy(string $fromPath, string $toPath): array
    {
        $response = $this->rpc('/2/files/copy_v2', [
            'from_path' => $this->guard($fromPath), 'to_path' => $this->guard($toPath), 'autorename' => false,
        ]);

        return $this->entry($response->json('metadata'));
    }

    public function download(string $fileId): string
    {
        $this->metadata($fileId);

        return $this->send(fn (PendingRequest $http) => $http
            ->withHeaders(['Dropbox-API-Arg' => $this->headerJson(['path' => $fileId])])
            ->withBody('', 'application/octet-stream')
            ->post(self::CONTENT.'/2/files/download'))->body();
    }

    /** A direct link to the file, valid for about four hours. */
    public function temporaryLink(string $fileId): string
    {
        $this->metadata($fileId);

        return (string) $this->rpc('/2/files/get_temporary_link', ['path' => $fileId])->json('link');
    }

    /**
     * Normalise a path and refuse anything outside the receipts root. Dropbox paths are
     * case-insensitive, so the comparison is too.
     */
    public function guard(string $path): string
    {
        $path = '/'.trim(str_replace('\\', '/', $path), '/');
        foreach (explode('/', $path) as $segment) {
            if ($segment === '..' || $segment === '.') {
                throw new DropboxException("Refusing path with relative segments: {$path}");
            }
        }

        $root = mb_strtolower($this->root());
        $lower = mb_strtolower($path);
        if ($lower !== $root && ! str_starts_with($lower, $root.'/')) {
            throw new DropboxException("Refusing path outside {$this->root()}: {$path}");
        }

        return $path;
    }

    // ── Plumbing ──

    private function rpc(string $endpoint, array $arguments): Response
    {
        return $this->send(fn (PendingRequest $http) => $http->asJson()->post(self::API.$endpoint, $arguments));
    }

    /** Run a request with a valid access token; one retry with a fresh token on 401. */
    private function send(callable $request): Response
    {
        $response = $request(Http::withToken($this->accessToken())->timeout(60));
        if ($response->status() === 401) {
            Cache::forget(self::TOKEN_CACHE_KEY);
            $response = $request(Http::withToken($this->accessToken())->timeout(60));
        }

        if ($response->successful()) {
            return $response;
        }

        $summary = (string) ($response->json('error_summary') ?? $response->body());
        if ($response->status() === 409 && str_contains($summary, 'conflict')) {
            throw new DropboxConflict('A file or folder with this name already exists in Dropbox.');
        }
        if ($response->status() === 409 && str_contains($summary, 'not_found')) {
            throw new DropboxNotFound('The file or folder was not found in Dropbox.');
        }

        throw new DropboxException("Dropbox request failed ({$response->status()}): ".mb_substr($summary, 0, 200));
    }

    private function accessToken(): string
    {
        if ($cached = Cache::get(self::TOKEN_CACHE_KEY)) {
            return $cached;
        }
        if (! $this->isConfigured()) {
            throw new DropboxNotConnected('Dropbox app key and secret are not configured.');
        }
        $refreshToken = BusinessProfile::current()->dropbox_refresh_token;
        if (! $refreshToken) {
            throw new DropboxNotConnected('Dropbox is not connected. Connect it in Settings.');
        }

        $response = $this->tokenRequest(['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken]);

        return $this->rememberAccessToken($response);
    }

    private function tokenRequest(array $form): Response
    {
        $response = Http::asForm()
            ->withBasicAuth(config('services.dropbox.app_key'), config('services.dropbox.app_secret'))
            ->timeout(30)
            ->post(self::API.'/oauth2/token', $form);

        if (! $response->successful() || ! $response->json('access_token')) {
            $reason = $response->json('error_description') ?? $response->json('error') ?? "HTTP {$response->status()}";
            throw new DropboxException("Dropbox refused the authorisation: {$reason}");
        }

        return $response;
    }

    private function rememberAccessToken(Response $response): string
    {
        $token = (string) $response->json('access_token');
        // Access tokens last about four hours; renew a little early.
        $ttl = max(60, (int) $response->json('expires_in', 14400) - 300);
        Cache::put(self::TOKEN_CACHE_KEY, $token, $ttl);

        return $token;
    }

    /** HTTP headers must be ASCII: json_encode escapes everything else as \uXXXX. */
    private function headerJson(array $arguments): string
    {
        return str_replace("\x7f", '\u007f', json_encode($arguments));
    }

    private function entry(?array $data): array
    {
        return [
            'id' => $data['id'] ?? null,
            'name' => (string) ($data['name'] ?? ''),
            'path' => (string) ($data['path_display'] ?? $data['path_lower'] ?? ''),
            'is_folder' => ($data['.tag'] ?? null) === 'folder',
        ];
    }
}
