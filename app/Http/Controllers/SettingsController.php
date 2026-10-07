<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateBusinessProfileRequest;
use App\Models\BusinessProfile;
use App\Models\StandingDocument;
use App\Services\Dropbox\DropboxClient;
use App\Services\Dropbox\DropboxException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function show(DropboxClient $dropbox): Response
    {
        return Inertia::render('Settings/Profile', [
            'dropbox' => $this->dropboxStatus($dropbox),
            'standing_documents' => StandingDocument::orderBy('label')->get(['id', 'label', 'row_keyword', 'source_path', 'filename'])->all(),
            'profile' => BusinessProfile::current()->only([
                'name', 'address_line_1', 'address_line_2', 'postal_code', 'city', 'country',
                'uid', 'vat_id', 'iban', 'qr_iban', 'email', 'sender_name', 'logo_path',
                'default_currency', 'default_vat_rate', 'invoice_number_prefix', 'reminder_days_after_due',
            ]),
        ]);
    }

    public function storeStandingDocument(Request $request, DropboxClient $dropbox): RedirectResponse
    {
        $data = $request->validate([
            'label' => 'required|string|max:100',
            'row_keyword' => 'required|string|min:3|max:100',
            'source_path' => 'required|string|max:1000',
            'filename' => 'nullable|string|max:200',
        ]);
        $data['source_path'] = trim($data['source_path'], '/ ');
        $data['filename'] = filled($data['filename'] ?? null) ? $data['filename'] : basename($data['source_path']);

        try {
            if ($dropbox->isConnected() && ! $dropbox->exists($dropbox->root().'/'.$data['source_path'])) {
                return back()->withErrors(['source_path' => 'No such file in the Dropbox receipts folder.']);
            }
        } catch (DropboxException $e) {
            return back()->withErrors(['source_path' => $e->getMessage()]);
        }

        StandingDocument::create($data);

        return back()->with('success', 'Standing document added.');
    }

    public function destroyStandingDocument(StandingDocument $document): RedirectResponse
    {
        $document->delete();

        return back()->with('success', 'Standing document removed. Copies already made stay in Dropbox.');
    }

    private function dropboxStatus(DropboxClient $dropbox): array
    {
        $profile = BusinessProfile::current();
        $status = [
            'configured' => $dropbox->isConfigured(),
            'connected' => $dropbox->isConnected(),
            'account' => $profile->dropbox_account_label,
            'connected_at' => $profile->dropbox_connected_at?->toIso8601String(),
            'root' => config('services.dropbox.receipts_root'),
            'root_ok' => null,
            'problem' => null,
        ];

        if ($status['connected']) {
            try {
                $status['root_ok'] = $dropbox->exists($dropbox->root());
            } catch (DropboxException $e) {
                $status['problem'] = $e->getMessage();
            } catch (\Throwable) {
                $status['problem'] = 'Dropbox could not be reached.';
            }
        }

        return $status;
    }

    public function updateProfile(UpdateBusinessProfileRequest $request): RedirectResponse
    {
        BusinessProfile::current()->update($request->validated());

        return back()->with('success', 'Business profile updated.');
    }
}
