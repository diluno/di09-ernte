<?php

namespace App\Support;

use App\Models\Estimate;
use App\Models\EstimateLine;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

class EstimateProjections
{
    public const PER_PAGE = 50;

    /**
     * Paginated estimate list rows for /estimates, 50 per page.
     *
     * $filter: 'all' | 'draft' | 'sent' | 'accepted' | 'declined' | 'expired'.
     * 'expired' is virtual: status='sent' AND valid_until < today.
     *
     * @return LengthAwarePaginator<array>
     */
    public static function index(string $filter = 'all', ?string $search = null): LengthAwarePaginator
    {
        $q = Estimate::query()
            ->with(['client:id,name', 'project:id,name', 'lines:id,estimate_id,hours']);

        if ($filter === 'expired') {
            $q->where('status', 'sent')->whereDate('valid_until', '<', Carbon::today());
        } elseif (in_array($filter, ['draft', 'sent', 'accepted', 'declined'], true)) {
            $q->where('status', $filter);
        }

        if ($search) {
            $q->where(function ($w) use ($search) {
                $w->where('number', 'like', "%{$search}%")
                    ->orWhereHas('client', fn ($cq) => $cq->where('name', 'like', "%{$search}%"));
            });
        }

        return $q->orderByRaw('COALESCE(issued_on, created_at) DESC')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Estimate $e) => [
                'id' => $e->id,
                'number' => $e->number,
                'title' => $e->title,
                'status' => $e->status,
                'expired' => $e->expired,
                'issued_on' => $e->issued_on?->toDateString(),
                'valid_until' => $e->valid_until?->toDateString(),
                'hours' => (float) round((float) $e->lines->sum('hours'), 2),
                'total' => (float) round($e->total_rappen / 100, 2),
                'client' => ['id' => $e->client->id, 'name' => $e->client->name],
                'project_name' => $e->project?->name,
            ]);
    }

    /** Full single-estimate detail array (shared by the web show page and the API). */
    public static function detail(Estimate $estimate): array
    {
        $estimate->loadMissing(['client', 'project', 'convertedInvoice:id,number', 'lines', 'sections', 'events']);

        $hours = (float) $estimate->lines->sum('hours');
        $weightedRate = $hours > 0
            ? $estimate->lines->sum(fn (EstimateLine $l) => (float) $l->hours * $l->rate_rappen) / $hours
            : null;
        $sentEvent = $estimate->events->where('kind', 'sent')->sortByDesc('occurred_at')->first();
        $today = Carbon::today();
        $validUntil = $estimate->valid_until?->copy()->startOfDay();

        return [
            'id' => $estimate->id,
            'number' => $estimate->number,
            'status' => $estimate->status,
            'expired' => $estimate->expired,
            // Whole days until valid_until for a sent estimate (0 = last valid day),
            // and how long ago it ran out once expired; null when not applicable.
            'days_left' => ($estimate->status === 'sent' && $validUntil && ! $estimate->expired)
                ? (int) $today->diffInDays($validUntil, false) : null,
            'days_expired' => ($estimate->expired && $validUntil)
                ? max(0, (int) $validUntil->diffInDays($today, false)) : null,
            'issued_channel' => $sentEvent ? (($sentEvent->payload['manual'] ?? false) ? 'manual' : 'email') : null,
            'avg_rate' => $weightedRate !== null ? round($weightedRate / 100, 2) : null,
            'title' => $estimate->title,
            'client' => $estimate->client->only('id', 'name'),
            'recipients' => $estimate->effectiveRecipients(),
            'project_name' => $estimate->project?->name,
            'issued_on' => $estimate->issued_on?->toDateString(),
            'valid_until' => $estimate->valid_until?->toDateString(),
            'subtotal' => round($estimate->subtotal_rappen / 100, 2),
            'vat' => round($estimate->vat_rappen / 100, 2),
            'rounding' => round($estimate->rounding_rappen / 100, 2),
            'total' => round($estimate->total_rappen / 100, 2),
            'vat_rate' => (float) $estimate->vat_rate,
            'notes' => $estimate->notes,
            'assumptions' => $estimate->assumptions ?? [],
            'hours' => EstimateScope::totalHours($estimate),
            // Flat line list, kept for existing consumers; `section` names the group.
            'lines' => $estimate->lines->sortBy('sort_order')->values()->map(fn (EstimateLine $l) => [
                'id' => $l->id,
                'section' => $l->estimate_section_id ? $estimate->sections->firstWhere('id', $l->estimate_section_id)?->heading() : null,
                'title' => $l->title,
                'description' => $l->description,
                'hours' => (float) $l->hours, 'rate' => round($l->rate_rappen / 100, 2),
                'amount' => round($l->amount_rappen / 100, 2),
            ])->all(),
            'sections' => collect(EstimateScope::groups($estimate))
                ->filter(fn (array $g) => $g['section'] !== null)
                ->map(fn (array $g) => [
                    'label' => $g['section']->label,
                    'title' => $g['section']->title,
                    'hours' => $g['hours'],
                    'amount' => round($g['amount_rappen'] / 100, 2),
                    'lines_count' => $g['lines']->count(),
                ])->values()->all(),
            'converted_invoice' => $estimate->convertedInvoice
                ? ['id' => $estimate->convertedInvoice->id, 'number' => $estimate->convertedInvoice->number]
                : null,
        ];
    }

    /**
     * Top-of-page summary numbers. Sums are taken in integer rappen and returned
     * as CHF (like InvoiceProjections::stats), plus counts and an acceptance rate.
     * 'expired' uses the same rule as the Expired filter: sent, valid_until < today.
     */
    public static function stats(): array
    {
        $today = Carbon::today();

        // 'open' = all sent estimates (includes ones past valid_until — 'expired' is a virtual UI-only state, not a stored status).
        $open = (int) Estimate::open()->sum('total_rappen');
        $openCount = Estimate::open()->count();

        $expiredRow = Estimate::open()
            ->whereDate('valid_until', '<', $today)
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(total_rappen), 0) AS rappen')
            ->first();

        $acceptedYtdQuery = Estimate::query()
            ->where('status', 'accepted')
            ->whereYear('decided_at', Carbon::now()->year);
        $acceptedYtd = (int) (clone $acceptedYtdQuery)->sum('total_rappen');
        $acceptedYtdCount = (clone $acceptedYtdQuery)->count();

        $draftsRow = Estimate::query()
            ->where('status', 'draft')
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(total_rappen), 0) AS rappen')
            ->first();

        $accepted = Estimate::where('status', 'accepted')->count();
        $declined = Estimate::where('status', 'declined')->count();
        $decided = $accepted + $declined;

        return [
            'open' => round($open / 100, 2),
            'open_count' => $openCount,
            'expired' => round(((int) ($expiredRow->rappen ?? 0)) / 100, 2),
            'expired_count' => (int) ($expiredRow->n ?? 0),
            'accepted_ytd' => round($acceptedYtd / 100, 2),
            'accepted_ytd_count' => $acceptedYtdCount,
            'acceptance_rate' => $decided > 0 ? (int) round($accepted / $decided * 100) : null,
            'accepted_count' => $accepted,
            'decided_count' => $decided,
            'drafts' => round(((int) ($draftsRow->rappen ?? 0)) / 100, 2),
            'drafts_count' => (int) ($draftsRow->n ?? 0),
            'count' => Estimate::count(),
        ];
    }
}
