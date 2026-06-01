<?php

namespace App\Support;

use App\Models\Estimate;
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

    /** Top-of-page summary numbers in CHF (plus an acceptance-rate percentage). */
    public static function stats(): array
    {
        // 'open' = all sent estimates (includes ones past valid_until — 'expired' is a virtual UI-only state, not a stored status).
        $open = (int) Estimate::open()->sum('total_rappen');

        $acceptedYtd = (int) Estimate::query()
            ->where('status', 'accepted')
            ->whereYear('decided_at', Carbon::now()->year)
            ->sum('total_rappen');

        $accepted = Estimate::where('status', 'accepted')->count();
        $declined = Estimate::where('status', 'declined')->count();
        $decided = $accepted + $declined;

        return [
            'open' => round($open / 100, 2),
            'accepted_ytd' => round($acceptedYtd / 100, 2),
            'acceptance_rate' => $decided > 0 ? (int) round($accepted / $decided * 100) : null,
            'count' => Estimate::count(),
        ];
    }
}
