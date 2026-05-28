<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Support\InvoiceProjections;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InvoiceController extends Controller
{
    public function index(Request $request): Response
    {
        $filter = $request->string('filter', 'all')->toString();
        $search = $request->string('q')->toString() ?: null;

        return Inertia::render('Invoices/Index', [
            'invoices' => InvoiceProjections::index($filter, $search)->values(),
            'stats'    => InvoiceProjections::stats(),
            'counts'   => [
                'all'     => Invoice::count(),
                'draft'   => Invoice::where('status', 'draft')->count(),
                'sent'    => Invoice::where('status', 'sent')->count(),
                'overdue' => Invoice::where('status', 'sent')->whereDate('due_on', '<', now()->toDateString())->count(),
                'paid'    => Invoice::where('status', 'paid')->count(),
                'void'    => Invoice::where('status', 'void')->count(),
            ],
            'filters'  => ['filter' => $filter, 'q' => $search],
        ]);
    }
}
