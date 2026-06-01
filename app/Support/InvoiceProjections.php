<?php

namespace App\Support;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class InvoiceProjections
{
    public const PER_PAGE = 50;

    /**
     * Paginated invoice list rows for /invoices, 50 per page.
     *
     * $filter: 'all' | 'draft' | 'sent' | 'overdue' | 'paid' | 'void'.
     * 'overdue' is virtual: status='sent' AND due_on < today.
     *
     * @return LengthAwarePaginator<array>
     */
    public static function index(string $filter = 'all', ?string $search = null): LengthAwarePaginator
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
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Invoice $i) => [
            'id' => $i->id,
            'number' => $i->number,
            'title' => $i->title,
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

        // Paid invoices *issued* this calendar year (matches Harvest's "Paid YTD",
        // which buckets by issue date — not by when the payment landed).
        $paidYtd = (int) Invoice::query()
            ->where('status', 'paid')
            ->whereYear('issued_on', Carbon::now()->year)
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

    /** Full single-invoice detail array (shared by the web show page and the API). */
    public static function detail(Invoice $invoice): array
    {
        $invoice->loadMissing(['client', 'project', 'recurringInvoice:id,title', 'lines']);

        return [
            'id' => $invoice->id,
            'number' => $invoice->number,
            'status' => $invoice->status,
            'overdue' => $invoice->overdue,
            'title' => $invoice->title,
            'client' => $invoice->client->only('id', 'name'),
            'project_name' => $invoice->project?->name,
            'issued_on' => $invoice->issued_on?->toDateString(),
            'due_on' => $invoice->due_on?->toDateString(),
            'subtotal' => round($invoice->subtotal_rappen / 100, 2),
            'vat' => round($invoice->vat_rappen / 100, 2),
            'total' => round($invoice->total_rappen / 100, 2),
            'vat_rate' => (float) $invoice->vat_rate,
            'notes' => $invoice->notes,
            'recurring' => $invoice->recurringInvoice
                ? ['id' => $invoice->recurringInvoice->id, 'title' => $invoice->recurringInvoice->title]
                : null,
            'lines' => $invoice->lines->sortBy('sort_order')->values()->map(fn (InvoiceLine $l) => [
                'id' => $l->id, 'description' => $l->description,
                'hours' => (float) $l->hours, 'rate' => (int) round($l->rate_rappen / 100),
                'amount' => round($l->amount_rappen / 100, 2),
            ])->all(),
        ];
    }

    /**
     * Stacked monthly totals (CHF) for invoices issued in $year, split open vs paid,
     * plus the navigable year bounds. Used by the Invoices index chart.
     *
     * open = status 'sent' (unpaid; overdue folds in); paid = status 'paid'.
     * draft/void are excluded. Bucketed by issued_on month.
     */
    public static function monthlyIssued(int $year): array
    {
        $rows = Invoice::query()
            ->whereIn('status', ['sent', 'paid'])
            ->whereYear('issued_on', $year)
            ->selectRaw('MONTH(issued_on) AS m, status, SUM(total_rappen) AS cents')
            ->groupBy('m', 'status')
            ->get();

        $open = array_fill(1, 12, 0);
        $paid = array_fill(1, 12, 0);
        foreach ($rows as $r) {
            $month = (int) $r->m;
            if ($r->status === 'paid') {
                $paid[$month] += (int) $r->cents;
            } else {
                $open[$month] += (int) $r->cents;
            }
        }

        $labels = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        $months = [];
        for ($m = 1; $m <= 12; $m++) {
            $months[] = [
                'label' => $labels[$m - 1],
                'open' => round($open[$m] / 100, 2),
                'paid' => round($paid[$m] / 100, 2),
            ];
        }

        $minIssuedYear = Invoice::query()
            ->whereIn('status', ['sent', 'paid'])
            ->whereNotNull('issued_on')
            ->selectRaw('MIN(YEAR(issued_on)) AS y')
            ->value('y');

        $currentYear = (int) Carbon::now()->year;

        return [
            'year' => $year,
            'min_year' => $minIssuedYear !== null ? (int) $minIssuedYear : $year,
            'max_year' => $currentYear,
            'months' => $months,
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
