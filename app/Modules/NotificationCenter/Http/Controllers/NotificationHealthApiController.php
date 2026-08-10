<?php

namespace App\Modules\NotificationCenter\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\NotificationCenter\Services\NotificationHealthService;
use Illuminate\Http\JsonResponse;

class NotificationHealthApiController extends Controller
{
    public function __construct(
        protected NotificationHealthService $health,
    ) {}

    public function show(): JsonResponse
    {
        return response()->json($this->health->check());
    }
}
