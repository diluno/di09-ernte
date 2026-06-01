<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Support\InvoiceProjections;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filter = $request->string('filter', 'all')->toString();
        $search = $request->string('q')->toString() ?: null;

        return response()->json([
            'invoices' => InvoiceProjections::index($filter, $search),
            'stats'    => InvoiceProjections::stats(),
        ]);
    }

    public function show(Invoice $invoice): JsonResponse
    {
        return response()->json(InvoiceProjections::detail($invoice), 200, [], JSON_PRESERVE_ZERO_FRACTION);
    }
}
