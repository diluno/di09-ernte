<?php

namespace App\Http\Controllers;

use App\Services\Dropbox\DropboxClient;
use App\Services\Dropbox\DropboxException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class DropboxController extends Controller
{
    public function connect(Request $request, DropboxClient $dropbox): RedirectResponse
    {
        if (! $dropbox->isConfigured()) {
            return redirect()->route('settings.show')->with('error', 'Set DROPBOX_APP_KEY and DROPBOX_APP_SECRET in the environment first.');
        }

        $state = Str::random(40);
        $request->session()->put('dropbox_oauth_state', $state);

        return redirect()->away($dropbox->authorizeUrl(route('settings.dropbox.callback'), $state));
    }

    public function callback(Request $request, DropboxClient $dropbox): RedirectResponse
    {
        $expected = $request->session()->pull('dropbox_oauth_state');
        if (! $expected || ! hash_equals($expected, (string) $request->query('state'))) {
            return redirect()->route('settings.show')->with('error', 'Dropbox connection was not completed: the request could not be verified. Try again.');
        }
        if ($request->query('error') || ! $request->query('code')) {
            return redirect()->route('settings.show')->with('error', 'Dropbox connection was cancelled.');
        }

        try {
            $label = $dropbox->connect((string) $request->query('code'), route('settings.dropbox.callback'));
        } catch (DropboxException $e) {
            return redirect()->route('settings.show')->with('error', $e->getMessage());
        }

        return redirect()->route('settings.show')->with('success', "Dropbox connected ({$label}).");
    }

    public function disconnect(DropboxClient $dropbox): RedirectResponse
    {
        $dropbox->disconnect();

        return redirect()->route('settings.show')->with('success', 'Dropbox disconnected.');
    }
}
