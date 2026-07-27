<?php

namespace App\Mcp\Tools;

use App\Models\Estimate;
use Laravel\Mcp\Response;

/**
 * Shared lookup for the tools that act on a single estimate.
 *
 * Every one of them takes an explicit estimate number rather than resolving
 * "the estimate we were just discussing" from context — the lifecycle tools
 * are not reversible, so the caller has to name what it is acting on.
 */
trait ResolvesEstimates
{
    protected function findEstimate(?string $number): ?Estimate
    {
        if (! $number) {
            return null;
        }

        return Estimate::query()
            ->where('number', trim($number))
            ->with(['client', 'project', 'lines' => fn ($q) => $q->orderBy('sort_order')])
            ->first();
    }

    protected function notFound(?string $number): Response
    {
        return Response::error("No estimate found with number '{$number}'. Use list_estimates to see what exists.");
    }
}
