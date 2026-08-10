<?php

namespace App\Modules\NotificationCenter\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\NotificationCenter\Services\NotificationMonitoringService;
use Illuminate\Http\JsonResponse;

class NotificationMonitoringApiController extends Controller
{
    public function __construct(
        protected NotificationMonitoringService $monitoring,
    ) {}

    public function dashboard(): JsonResponse
    {
        return response()->json($this->monitoring->dashboard());
    }

    public function performance(): JsonResponse
    {
        return response()->json($this->monitoring->performanceSnapshot());
    }
}
