<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\RiskFlag;
use App\Services\Audit\AuditLogger;
use App\Services\Security\RiskMonitor;
use App\Support\QueryFilters;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Risk review: signals for staff to look at. Nothing here changes an account; staff decide
 * on any action through the normal, audited user and finance tools.
 */
class RiskController extends Controller
{
    public function index(Request $request): View
    {
        $filters = QueryFilters::from($request, [
            'status' => [Rule::in(['open', 'watching', 'reviewed', 'dismissed', 'all'])],
            'severity' => [Rule::in(['low', 'medium', 'high'])],
            'type' => [Rule::in(array_keys(RiskMonitor::TYPES))],
            'user' => ['integer', 'min:1'],
        ]);
        $status = $filters->get('status', 'open');

        $flags = RiskFlag::query()->with(['user:id,name,display_name,deleted_at', 'reviewer:id,name'])
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($filters->get('severity'), fn ($q, $s) => $q->where('severity', $s))
            ->when($filters->get('type'), fn ($q, $t) => $q->where('type', $t))
            ->when($filters->get('user'), fn ($q, $id) => $q->where('user_id', $id))
            ->orderByRaw("FIELD(severity, 'high', 'medium', 'low')")
            ->latest('last_seen_at')->latest('id')
            ->paginate(30)->withQueryString();

        return view('admin.risk.index', [
            'flags' => $flags,
            'filters' => $filters,
            'status' => $status,
            'types' => collect(RiskMonitor::TYPES)->map(fn ($t) => $t[0])->all(),
            'openCount' => RiskFlag::query()->where('status', 'open')->count(),
        ]);
    }

    public function review(Request $request, RiskFlag $flag, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['reviewed', 'dismissed'])],
            'note' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $done = DB::transaction(function () use ($request, $flag, $data, $audit) {
            $flag = RiskFlag::query()->lockForUpdate()->findOrFail($flag->id);
            if (! in_array($flag->status, ['open', 'watching'], true)) {
                return false;
            }

            $old = ['status' => $flag->status];
            $flag->forceFill([
                'status' => $data['decision'],
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
                'review_note' => $data['note'],
            ])->save();
            $audit->record('risk.'.$data['decision'], $flag, $old, ['status' => $flag->status],
                ['type' => $flag->type, 'user_id' => $flag->user_id, 'note' => $data['note']]);

            return true;
        });

        return $done
            ? back()->with('success', 'Risk flag marked as '.$data['decision'].'.')
            : back()->with('error', 'That flag was already closed by someone else.');
    }
}
