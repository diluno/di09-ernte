<?php

namespace App\Support;

use App\Models\Invoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class InvoiceProjections
{
    /**
     * Invoice list rows for /invoices.
     *
     * $filter: 'all' | 'draft' | 'sent' | 'overdue' | 'paid' | 'void'.
     * 'overdue' is virtual: status='sent' AND due_on < today.
     *
     * @return Collection<int, array>
     */
    public static function index(string $filter = 'all', ?string $search = null): Collection
    {
        $q = Invoice::query()
            ->with(['client:id,name', 'project:id,name', 'lines:id,invoice_id,hours']);

        if ($filter === 'overdue') {
            $q->where('status', 'sent')->whereDate('due_on', '<', Carbon::today());
        } elseif (in_array($filter, ['draft', 'sent', 'paid', 'void'], true)) {
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
            ->get()->map(fn (Invoice $i) => [
            'id' => $i->id,
            'number' => $i->number,
            'status' => $i->status,
            'overdue' => $i->overdue,
            'issued_on' => $i->issued_on?->toDateString(),
            'due_on' => $i->due_on?->toDateString(),
            'hours' => (float) round((float) $i->lines->sum('hours'), 2),
            'total' => (float) round($i->total_rappen / 100, 2),
            'client' => ['id' => $i->client->id, 'name' => $i->client->name],
            'project_name' => $i->project?->name,
        ]);
    }

    /** Top-of-page summary numbers in CHF. */
    public static function stats(): array
    {
        $outstanding = (int) Invoice::outstanding()->sum('total_rappen');

        $overdue = (int) Invoice::query()
            ->where('status', 'sent')
            ->whereDate('due_on', '<', Carbon::today())
            ->sum('total_rappen');

        $paidYtd = (int) Invoice::query()
            ->where('status', 'paid')
            ->whereYear('paid_at', Carbon::now()->year)
            ->sum('total_rappen');

        // Average days from issue to payment over paid invoices (null if none).
        $avg = Invoice::query()
            ->where('status', 'paid')
            ->whereNotNull('issued_on')
            ->whereNotNull('paid_at')
            ->selectRaw('AVG(DATEDIFF(paid_at, issued_on)) AS d')
            ->value('d');

        return [
            'outstanding' => round($outstanding / 100, 2),
            'overdue' => round($overdue / 100, 2),
            'paid_ytd' => round($paidYtd / 100, 2),
            'avg_days_to_pay' => $avg !== null ? (int) round((float) $avg) : null,
            'count' => Invoice::count(),
        ];
    }

    /** Outstanding (sent) total in rappen, keyed by client_id. */
    public static function outstandingByClient(): Collection
    {
        return Invoice::outstanding()
            ->selectRaw('client_id, SUM(total_rappen) AS rappen')
            ->groupBy('client_id')
            ->pluck('rappen', 'client_id');
    }
}
