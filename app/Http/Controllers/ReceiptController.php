<?php

namespace App\Http\Controllers;

use App\Jobs\ExtractReceipt;
use App\Jobs\FileReceipt;
use App\Jobs\PrepareReceiptPdf;
use App\Models\Receipt;
use App\Services\Dropbox\DropboxClient;
use App\Services\Dropbox\DropboxException;
use App\Services\Receipts\ReceiptFiler;
use App\Jobs\FetchVendorLogo;
use App\Services\Receipts\ReceiptPaths;
use App\Services\Receipts\VendorLogos;
use App\Support\Quarter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class ReceiptController extends Controller
{
    private const EXTENSIONS = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/heic' => 'heic', 'image/heif' => 'heic'];

    public function index(Request $request, DropboxClient $dropbox): Response
    {
        $filter = $request->string('filter', 'all')->toString();
        $month = $request->string('month')->toString() ?: null;

        $quarters = Receipt::whereNotNull('target_year')->selectRaw('DISTINCT target_year AS y, CEIL(target_month / 3) AS q')
            ->orderByDesc('y')->orderByDesc('q')->get()->map(fn ($r) => new Quarter((int) $r->y, (int) $r->q));
        $showAll = $request->string('quarter')->toString() === 'all';
        $quarter = $showAll ? null : (Quarter::parse($request->string('quarter')->toString()) ?? $quarters->first());

        // Receipts still being read have no folder yet; they show in every quarter until sorted.
        $inQuarter = fn ($q) => $quarter ? $q->where(fn ($w) => $w
            ->where(fn ($t) => $t->where('target_year', $quarter->year)->whereIn('target_month', $quarter->months()))
            ->orWhereNull('target_year')) : $q;

        $query = $inQuarter(Receipt::query())
            ->when($filter === 'attention', fn ($q) => $q->needsAttention())
            ->when($filter === 'unfiled', fn ($q) => $q->where('filing_status', '!=', 'filed'))
            ->when($month && preg_match('/^(\d{4})-(\d{2})$/', $month, $m), fn ($q) => $q->where('target_year', (int) $m[1])->where('target_month', (int) $m[2]))
            ->with('statementLine:id,source')
            ->orderByDesc('created_at')->orderByDesc('id');

        return Inertia::render('Receipts/Index', [
            'receipts' => $query->paginate(50)->withQueryString()->through(fn (Receipt $r) => $this->row($r)),
            'counts' => [
                'all' => $inQuarter(Receipt::query())->count(),
                'attention' => $inQuarter(Receipt::query())->needsAttention()->count(),
                'unfiled' => $inQuarter(Receipt::query())->where('filing_status', '!=', 'filed')->count(),
                'unmatched' => $inQuarter(Receipt::query())->whereNull('duplicate_of_id')->where(fn ($q) => $q->whereNull('match_state')->orWhere('match_state', 'proposed'))->count(),
                'numbered' => $inQuarter(Receipt::query())->whereNotNull('numbered_at')->count(),
            ],
            'quarter' => $quarter?->toArray(),
            'quarters' => $quarters->map->toArray()->all(),
            'months' => Receipt::whereNotNull('target_year')
                ->when($quarter, fn ($q) => $q->where('target_year', $quarter->year)->whereIn('target_month', $quarter->months()))
                ->selectRaw('target_year, target_month')->distinct()
                ->orderByDesc('target_year')->orderByDesc('target_month')->get()
                ->map(fn ($r) => sprintf('%d-%02d', $r->target_year, $r->target_month))->all(),
            'filters' => ['filter' => $filter, 'month' => $month, 'quarter' => $showAll ? 'all' : $quarter?->key()],
            'dropbox_connected' => $dropbox->isConnected(),
            'inbox_folder' => config('services.dropbox.inbox_folder', '_Inbox'),
        ]);
    }

    /** One file per request; the page uploads a batch one by one. */
    public function store(Request $request): JsonResponse
    {
        $request->validate(['file' => 'required|file|max:20480']);
        $file = $request->file('file');

        $mime = $this->mime($file->getMimeType(), $file->getClientOriginalExtension());
        if (! $mime) {
            return response()->json(['message' => 'Only PDF, JPEG, PNG and HEIC files can be uploaded.'], 422);
        }

        $hash = hash_file('sha256', $file->getRealPath());
        if ($existing = Receipt::where('content_hash', $hash)->whereNull('duplicate_of_id')->first()) {
            return response()->json(['status' => 'duplicate', 'receipt' => $this->row($existing)]);
        }

        $path = $file->storeAs('receipts', $hash.'.'.self::EXTENSIONS[$mime], 'local');
        $receipt = Receipt::create([
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'content_hash' => $hash,
            'original_mime' => $mime,
            'size_bytes' => $file->getSize(),
            'local_path' => $path,
        ]);

        Bus::chain([
            new PrepareReceiptPdf($receipt->id),
            new ExtractReceipt($receipt->id),
            new FileReceipt($receipt->id),
        ])->dispatch();

        return response()->json(['status' => 'created', 'receipt' => $this->row($receipt)], 201);
    }

    public function show(Receipt $receipt, ReceiptFiler $filer): Response
    {
        // A cheap look at where the file is now; the accountant or the scripts may have touched it.
        if ($receipt->dropbox_file_id) {
            try {
                $filer->refresh($receipt);
            } catch (DropboxException) {
                // Offline or disconnected: show what ernte last knew.
            }
        }

        return Inertia::render('Receipts/Show', [
            'receipt' => $this->row($receipt) + [
                'amounts' => $receipt->amounts ?? [],
                'invoice_number' => $receipt->invoice_number,
                'payment_method' => $receipt->payment_method,
                'note' => $receipt->note,
                'extraction_error' => $receipt->extraction_error,
                'filing_error' => $receipt->filing_error,
                'dropbox_path' => $receipt->dropbox_path,
                'has_text_layer' => $receipt->text_layer !== null,
                'paid_by' => ($line = $receipt->statementLine) ? [
                    'state' => $receipt->match_state,
                    'kind' => $line->source === 'viseca' ? 'Credit card' : 'Bank account',
                    'date' => $line->booked_on->toDateString(),
                    'payee' => $line->counterparty_name ?? $line->description,
                    'amount' => round($line->amount_rappen / 100, 2),
                    'original' => $line->source === 'viseca' && $line->original_currency !== 'CHF'
                        ? ['amount' => round($line->original_amount_minor / 100, 2), 'currency' => $line->original_currency] : null,
                    'quarter' => Quarter::of($line->booked_on)->key(),
                ] : null,
                'is_photo' => $receipt->isPhoto(),
                'file_url' => "/receipts/{$receipt->id}/file",
                'previewable' => $receipt->dropbox_file_id !== null || str_ends_with((string) $receipt->local_path, '.pdf'),
            ],
        ]);
    }

    public function file(Receipt $receipt, DropboxClient $dropbox): HttpResponse
    {
        // Always served inline by ernte itself: a Dropbox temporary link makes the browser
        // download the file instead of showing it in the preview frame.
        $headers = ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="receipt.pdf"', 'Cache-Control' => 'private, max-age=300'];

        if ($receipt->local_path && Storage::disk('local')->exists($receipt->local_path)) {
            return response()->file(Storage::disk('local')->path($receipt->local_path), str_ends_with($receipt->local_path, '.pdf') ? $headers : []);
        }
        abort_unless($receipt->dropbox_file_id, 404);

        try {
            return response($dropbox->download($receipt->dropbox_file_id), 200, $headers);
        } catch (DropboxException) {
            abort(404);
        }
    }

    public function update(Request $request, Receipt $receipt, ReceiptFiler $filer): RedirectResponse
    {
        $data = $request->validate([
            'vendor' => 'nullable|string|max:255',
            'vendor_domain' => 'nullable|string|max:255',
            'document_date' => 'nullable|date',
            'total' => ['nullable', 'regex:/^-?\d+(\.\d{1,2})?$/'],
            'currency' => 'nullable|alpha|size:3',
            'invoice_number' => 'nullable|string|max:255',
            'payment_method' => 'nullable|in:bank,card,unknown',
            'note' => 'nullable|string|max:2000',
            'target_year' => 'required|integer|min:2000|max:2100',
            'target_month' => 'required|integer|min:1|max:12',
            'filename' => 'nullable|string|max:200',
        ]);

        $fields = [
            'vendor' => $data['vendor'] ?? null,
            'vendor_domain' => VendorLogos::normalise($data['vendor_domain'] ?? null),
            'document_date' => $data['document_date'] ?? null,
            'total_minor' => isset($data['total']) ? (int) round(((float) $data['total']) * 100) : null,
            'currency' => isset($data['currency']) ? strtoupper($data['currency']) : null,
            'invoice_number' => $data['invoice_number'] ?? null,
            'payment_method' => $data['payment_method'] ?? null,
        ];
        $previousDomain = $receipt->vendor_domain;
        $receipt->fill($fields);
        $domainChanged = $receipt->isDirty('vendor_domain');
        if ($receipt->isDirty(array_keys($fields))) {
            $receipt->fields_edited = true;
        }
        $receipt->note = $data['note'] ?? null;
        if ($domainChanged) {
            $receipt->shareVendorDomain($previousDomain);
        }
        if (app(VendorLogos::class)->wanted($receipt->vendor_domain)) {
            FetchVendorLogo::dispatch($receipt->vendor_domain);
        }

        $year = (int) $data['target_year'];
        $month = (int) $data['target_month'];
        $monthChanged = $year !== $receipt->target_year || $month !== $receipt->target_month;
        $newName = filled($data['filename'] ?? null) ? ReceiptPaths::sanitise($data['filename']) : null;
        $nameChanged = $newName !== null && $newName !== $receipt->filename;
        if ($monthChanged) {
            $receipt->target_edited = true;
        }

        if ($receipt->filing_status === 'filed' && ($monthChanged || $nameChanged)) {
            $receipt->save();
            try {
                $filer->relocate($receipt, $year, $month, $nameChanged ? $newName : null);
            } catch (\DomainException|DropboxException $e) {
                return back()->with('error', $e->getMessage());
            }

            return back()->with('success', 'Receipt saved and moved in Dropbox.');
        }

        $receipt->target_year = $year;
        $receipt->target_month = $month;
        if ($nameChanged) {
            $receipt->filename = $newName;
        }
        $receipt->save();

        return back()->with('success', 'Receipt saved.');
    }

    public function extract(Receipt $receipt): RedirectResponse
    {
        $receipt->update(['extraction_status' => 'pending', 'extraction_error' => null]);
        ExtractReceipt::dispatch($receipt->id);

        return back()->with('success', 'Reading the receipt again…');
    }

    public function refile(Receipt $receipt): RedirectResponse
    {
        if ($receipt->filing_status === 'filed') {
            return back()->with('error', 'This receipt is already in Dropbox.');
        }
        if ($receipt->duplicate_of_id) {
            return back()->with('error', 'This is a duplicate; delete it from the inbox in Dropbox instead.');
        }
        // For a file waiting in the Dropbox inbox, "file now" is Sam confirming the month shown.
        $receipt->update($receipt->dropbox_file_id
            ? ['filing_status' => 'inbox', 'filing_error' => null, 'target_edited' => true]
            : ['filing_status' => 'pending', 'filing_error' => null]);
        FileReceipt::dispatch($receipt->id);

        return back()->with('success', 'Filing the receipt…');
    }

    /** Look into the Dropbox inbox now instead of waiting for the next scheduled check. */
    public function checkInbox(DropboxClient $dropbox, \App\Services\Receipts\DropboxIntake $intake): RedirectResponse
    {
        if (! $dropbox->isConnected()) {
            return back()->with('error', 'Dropbox is not connected.');
        }

        try {
            $result = $intake->scanInbox();
        } catch (DropboxException $e) {
            return back()->with('error', $e->getMessage());
        }

        $message = $result['new'] === 0 ? 'Nothing new in the Dropbox inbox.' : "{$result['new']} new receipt(s) found in the Dropbox inbox.";
        if ($result['duplicates']) {
            $message .= " {$result['duplicates']} duplicate(s).";
        }
        if ($result['skipped']) {
            $message .= " {$result['skipped']} file(s) skipped: only PDFs are picked up.";
        }

        return back()->with('success', $message);
    }

    /** Removes the record only. ernte never deletes from Dropbox. */
    public function destroy(Receipt $receipt): RedirectResponse
    {
        if ($receipt->local_path) {
            Storage::disk('local')->delete($receipt->local_path);
        }
        $filed = $receipt->dropbox_file_id !== null;
        $receipt->delete();

        return redirect('/receipts')->with('success', $filed
            ? 'Receipt removed from ernte. The file stays in Dropbox.'
            : 'Receipt removed.');
    }

    /** The vendor's stored site icon, or null when there is none (yet). */
    public static function logoUrl(?string $domain): ?string
    {
        return app(VendorLogos::class)->has($domain) ? "/vendor-logos/{$domain}" : null;
    }

    public function logo(string $domain, VendorLogos $logos): HttpResponse
    {
        abort_unless(VendorLogos::normalise($domain) === $domain && $logos->has($domain), 404);

        return response()->file(Storage::disk('local')->path($logos->path($domain)), [
            'Content-Type' => 'image/png', 'Cache-Control' => 'private, max-age=604800',
        ]);
    }

    /** finfo reports HEIC inconsistently across versions; fall back on the extension for it. */
    private function mime(?string $detected, string $extension): ?string
    {
        if (isset(self::EXTENSIONS[$detected])) {
            return $detected;
        }
        if (in_array(strtolower($extension), ['heic', 'heif'], true) && in_array($detected, ['application/octet-stream', 'video/quicktime', null], true)) {
            return 'image/heic';
        }

        return null;
    }

    private function row(Receipt $receipt): array
    {
        return [
            'id' => $receipt->id,
            'original_name' => $receipt->original_name,
            'filename' => $receipt->filename,
            'vendor' => $receipt->vendor,
            'vendor_domain' => $receipt->vendor_domain,
            'logo_url' => self::logoUrl($receipt->vendor_domain),
            'document_date' => $receipt->document_date?->toDateString(),
            'total' => $receipt->total_minor !== null ? round($receipt->total_minor / 100, 2) : null,
            'currency' => $receipt->currency,
            'confidence' => $receipt->confidence,
            'target_year' => $receipt->target_year,
            'target_month' => $receipt->target_month,
            'folder' => ReceiptPaths::label($receipt->target_year, $receipt->target_month),
            'extraction_status' => $receipt->extraction_status,
            'filing_status' => $receipt->filing_status,
            'source' => $receipt->source,
            'is_duplicate' => $receipt->duplicate_of_id !== null,
            'needs_attention' => $receipt->isFlagged(),
            'number_prefix' => $receipt->numberPrefix(),
            'match_state' => $receipt->match_state,
            // The row it is matched to settles how it was paid; before that, what the document says.
            'paid_by_card' => $receipt->statementLine
                ? $receipt->statementLine->source === 'viseca'
                : $receipt->payment_method === 'card',
            'uploaded_at' => $receipt->created_at?->toIso8601String(),
        ];
    }
}
