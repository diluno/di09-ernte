<?php

namespace App\Mcp\Tools;

use App\Models\Invoice;
use Laravel\Mcp\Response;

/**
 * Shared lookup for the tools that act on a single invoice. Like estimates,
 * the caller must name the invoice by number — sending is not reversible.
 */
trait ResolvesInvoices
{
    protected function findInvoice(?string $number): ?Invoice
    {
        if (! $number) {
            return null;
        }

        return Invoice::query()
            ->where('number', trim($number))
            ->with(['client', 'project', 'lines' => fn ($q) => $q->orderBy('sort_order')])
            ->first();
    }

    protected function notFound(?string $number): Response
    {
        return Response::error("No invoice found with number '{$number}'. Use list_invoices to see what exists.");
    }
}
