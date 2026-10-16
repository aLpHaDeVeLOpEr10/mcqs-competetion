<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\QueryFilters;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Read-only. Audit records are append-only at the database level.
 */
class AuditLogController extends Controller
{
    public function index(Request $request): View
    {
        $filters = QueryFilters::from($request, [
            'action' => ['string', 'max:100'],
            'actor' => ['integer', 'min:1'],
            'from' => ['date'],
            'to' => ['date'],
            'request' => ['uuid'],
        ]);

        $query = AuditLog::query()->with('actor:id,name,display_name,deleted_at');
        $query->when($filters->get('action'), fn ($q, $a) => $q->where('action', 'like', addcslashes($a, '\\%_').'%'))
            ->when($filters->get('actor'), fn ($q, $id) => $q->where('actor_id', $id))
            ->when($filters->get('request'), fn ($q, $id) => $q->where('request_id', $id))
            ->when($filters->get('from'), fn ($q, $d) => $q->where('created_at', '>=', $d))
            ->when($filters->get('to'), fn ($q, $d) => $q->where('created_at', '<', date('Y-m-d', strtotime($d.' +1 day'))));

        return view('admin.audit.index', [
            'logs' => $query->latest('id')->paginate(30)->withQueryString(),
            'filters' => $filters,
            'actions' => AuditLog::query()->distinct()->orderBy('action')->pluck('action'),
        ]);
    }

    public function show(AuditLog $log): View
    {
        return view('admin.audit.show', ['log' => $log->load('actor:id,name,display_name,deleted_at')]);
    }
}
