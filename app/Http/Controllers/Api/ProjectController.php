<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Support\DashboardProjections;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filter = $request->string('filter', 'active')->toString();
        $search = $request->string('q')->toString() ?: null;

        return response()->json([
            'projects' => DashboardProjections::projects($filter, $search)->values(),
            'stats'    => DashboardProjections::stats($request->user()),
            'counts'   => [
                'active'   => Project::active()->count(),
                'all'      => Project::count(),
                'archived' => Project::archived()->count(),
            ],
        ]);
    }
}
