<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Permission;
use App\Exceptions\AdministrativeActionException;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\PaymentTransaction;
use App\Models\ReportExport;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\Account\PlayerDashboard;
use App\Services\Reports\FinanceReconciliation;
use App\Services\Reports\ReportCatalog;
use App\Services\Reports\ReportExportService;
use App\Services\Reports\ReportMetrics;
use App\Services\Reports\ReportPeriod;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reports from persisted data only. Every list is paginated and every total
 * is an SQL aggregate. Financial figures need finance.view in addition to
 * reports.view; per-user financial and audit details need their own permissions.
 */
class ReportController extends Controller
{
    public function __construct(protected ReportCatalog $catalog) {}

    public function index(Request $request, ReportMetrics $metrics): View
    {
        $period = ReportPeriod::fromRequest($request);

        return view('admin.reports.index', [
            'period' => $period,
            'activity' => $metrics->activity($period),
            'finance' => $request->user()->can(Permission::FinanceView->value) ? $metrics->finance($period) : null,
        ]);
    }

    public function competitions(Request $request): View
    {
        $period = ReportPeriod::fromRequest($request);

        return view('admin.reports.competitions', [
            'period' => $period,
            'rows' => $this->catalog->page('competitions', $period, 25),
            'totals' => $this->catalog->totals('competitions', $period),
        ]);
    }

    public function finance(Request $request, FinanceReconciliation $reconciliation, string $report = 'reconciliation'): View
    {
        $period = ReportPeriod::fromRequest($request);
        abort_unless($report === 'reconciliation' || (isset(ReportCatalog::REPORTS[$report]) && $report !== 'competitions'), 404);

        return view('admin.reports.finance', [
            'period' => $period,
            'report' => $report,
            'reconciliation' => $report === 'reconciliation' ? $reconciliation->for($period) : null,
            'rows' => $report === 'reconciliation' ? null : $this->catalog->query($report, $period)->paginate(30)->withQueryString(),
            'totals' => $report === 'reconciliation' ? null : $this->catalog->totals($report, $period),
        ]);
    }

    public function user(Request $request, User $user, PlayerDashboard $dashboard): View
    {
        $viewer = $request->user();
        $seeFinance = $viewer->can(Permission::FinanceView->value);

        return view('admin.reports.user', [
            'user' => $user->load('wallet'),
            'participations' => $user->roomParticipations()
                ->with(['room:id,name,status,entry_fee_minor,currency', 'room.session:id,room_id,results_published_at', 'result:id,room_participant_id,rank,correct_count,correct_response_time_ms', 'prizePayout:id,room_participant_id,status,net_amount_minor,amount_minor'])
                ->latest('joined_at')->paginate(15)->withQueryString(),
            'wins' => $user->roomParticipations()->whereHas('result', fn ($q) => $q->where('is_winner', true))
                ->whereHas('room.session', fn ($q) => $q->whereNotNull('results_published_at'))->count(),
            'breakdown' => $seeFinance ? $dashboard->walletBreakdown($user) : null,
            'deposits' => $seeFinance ? PaymentTransaction::query()->where('user_id', $user->id)->latest('id')->limit(10)->get() : null,
            'withdrawals' => $seeFinance ? Withdrawal::query()->where('user_id', $user->id)->latest('id')->limit(10)->get() : null,
            'history' => $viewer->can(Permission::AuditView->value)
                ? AuditLog::query()->where(fn ($q) => $q->where('auditable_type', $user->getMorphClass())->where('auditable_id', $user->id))
                    ->orWhere('actor_id', $user->id)->with('actor:id,name')->latest('id')->limit(20)->get()
                : null,
        ]);
    }

    public function export(Request $request, ReportExportService $exports): RedirectResponse
    {
        $data = $request->validate(['report' => ['required', 'string', 'in:'.implode(',', array_keys(ReportCatalog::REPORTS))]]);

        try {
            $exports->request($request->user(), $data['report'], ReportPeriod::fromRequest($request));
        } catch (AdministrativeActionException $e) {
            abort(403, $e->getMessage());
        }

        return redirect()->route('admin.exports.index')->with('success', 'Export queued. It will appear here when ready.');
    }

    public function exports(Request $request): View
    {
        return view('admin.reports.exports', [
            'exports' => ReportExport::query()->where('user_id', $request->user()->id)->latest('id')->paginate(20),
        ]);
    }

    public function download(Request $request, ReportExport $export): StreamedResponse
    {
        // Only the requester, only while they still hold the permission, only while not expired.
        abort_unless($export->user_id === $request->user()->id && ReportCatalog::allowed($request->user(), $export->report), 403);
        abort_if($export->status !== 'done' || $export->isExpired() || ! Storage::disk('local')->exists((string) $export->path), 404);

        return Storage::disk('local')->download($export->path, $export->report.'-'.$export->created_at->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
