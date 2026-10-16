<?php

namespace App\Services\Reports;

use App\Enums\Permission;
use App\Exceptions\AdministrativeActionException;
use App\Jobs\GenerateReportExport;
use App\Models\ReportExport;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Requests CSV exports. Building the file is always queued (GenerateReportExport),
 * so a large export never ties up a web request. Every export is audited.
 */
class ReportExportService
{
    public function __construct(protected AuditLogger $audit) {}

    public function request(User $user, string $report, ReportPeriod $period): ReportExport
    {
        if (! $user->can(Permission::ReportsExport->value) || ! ReportCatalog::allowed($user, $report)) {
            throw new AdministrativeActionException('You are not allowed to export this report.');
        }

        return DB::transaction(function () use ($user, $report, $period) {
            $export = tap((new ReportExport)->forceFill([
                'user_id' => $user->id,
                'report' => $report,
                'filters' => $period->toQuery(),
                'status' => 'queued',
            ]))->save();

            $this->audit->record('report.exported', $export, null, ['report' => $report, 'period' => $period->label()], actor: $user);
            GenerateReportExport::dispatch($export->id)->afterCommit();

            return $export;
        });
    }
}
