<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class ReportController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('Reports/Placeholder');
    }
}
