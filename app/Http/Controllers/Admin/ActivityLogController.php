<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use App\Services\Audit\AuditChangePresenter;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ActivityLogController extends Controller
{
    private const INDEX_COLUMNS = [
        'id',
        'user_id',
        'action',
        'module',
        'description',
        'subject_type',
        'subject_id',
        'ip_address',
        'user_agent',
        'created_at',
        'updated_at',
    ];

    public function index(Request $request)
    {
        $this->authorize('viewAny', ActivityLog::class);

        $query = ActivityLog::query()
            ->select(self::INDEX_COLUMNS)
            ->with('user')
            ->orderByDesc('created_at');

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('description', 'like', "%{$search}%")
                    ->orWhere('module', 'like', "%{$search}%")
                    ->orWhere('ip_address', 'like', "%{$search}%")
                    ->orWhere('old_values', 'like', "%{$search}%")
                    ->orWhere('new_values', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($userQuery) use ($search) {
                        $userQuery->where('name', 'like', "%{$search}%");
                    });
            });
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->filled('module')) {
            $query->where('module', $request->module);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $activityLogs = $query->paginate(20)->withQueryString();

        $activityLogs->getCollection()->transform(function (ActivityLog $log) {
            return [
                'id' => $log->id,
                'action' => $log->action,
                'action_label' => $log->action_label,
                'module' => $log->module,
                'description' => $log->description,
                'ip_address' => $log->ip_address,
                'created_at' => $log->created_at->format('d/m/Y H:i'),
                'user' => $log->user ? [
                    'id' => $log->user->id,
                    'name' => $log->user->name,
                ] : null,
            ];
        });

        $users = User::orderBy('name')->get(['id', 'name']);

        return Inertia::render('Admin/ActivityLogs/Index', [
            'activityLogs' => $activityLogs,
            'users' => $users,
            'modules' => ActivityLog::availableModules(),
            'filters' => $request->only(['search', 'user_id', 'module', 'date_from', 'date_to']),
        ]);
    }

    public function show(Request $request, ActivityLog $activityLog)
    {
        $this->authorize('view', $activityLog);

        $activityLog->load('user', 'subject');

        return Inertia::render('Admin/ActivityLogs/Show', [
            'activityLog' => [
                'id' => $activityLog->id,
                'action' => $activityLog->action,
                'action_label' => $activityLog->action_label,
                'module' => $activityLog->module,
                'description' => $activityLog->description,
                'ip_address' => $activityLog->ip_address,
                'user_agent' => $activityLog->user_agent,
                'browser' => $activityLog->browser,
                'created_at' => $activityLog->created_at->format('d/m/Y H:i:s'),
                'user' => $activityLog->user ? [
                    'id' => $activityLog->user->id,
                    'name' => $activityLog->user->name,
                    'email' => $activityLog->user->email,
                ] : null,
                'subject' => $activityLog->subject ? [
                    'type' => class_basename($activityLog->subject),
                    'module' => $activityLog->module,
                    'label' => $activityLog->subject_display_name,
                    'id' => $activityLog->subject_id,
                ] : null,
                'changes' => AuditChangePresenter::present(
                    $activityLog->old_values,
                    $activityLog->new_values,
                    $activityLog->action,
                ),
                'has_change_data' => !empty($activityLog->old_values) || !empty($activityLog->new_values),
            ],
        ]);
    }
}
