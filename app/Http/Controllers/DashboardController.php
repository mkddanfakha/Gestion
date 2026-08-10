<?php

namespace App\Http\Controllers;

use App\Services\DashboardService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function __construct(
        protected DashboardService $dashboardService,
    ) {}

    public function index(Request $request)
    {
        $this->checkPermission($request, 'dashboard', 'view');

        $period = $this->dashboardService->resolvePeriod($request);
        $dashboard = $this->dashboardService->build($request->user(), $period);

        return Inertia::render('Dashboard', $dashboard);
    }
}
