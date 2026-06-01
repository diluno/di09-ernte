<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Estimate;
use App\Support\EstimateProjections;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EstimateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filter = $request->string('filter', 'all')->toString();
        $search = $request->string('q')->toString() ?: null;

        return response()->json([
            'estimates' => EstimateProjections::index($filter, $search),
            'stats'     => EstimateProjections::stats(),
        ]);
    }

    public function show(Estimate $estimate): JsonResponse
    {
        return response()->json(EstimateProjections::detail($estimate), 200, [], JSON_PRESERVE_ZERO_FRACTION);
    }
}
