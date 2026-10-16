<?php

namespace App\Jobs;

use App\Models\ReportExport;
use App\Services\Reports\ReportCatalog;
use App\Services\Reports\ReportPeriod;
use App\Support\CsvWriter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Writes a report to a private CSV file, streaming rows in id order (chunks of
 * 1,000) so memory stays flat however large the report is. Cells are made
 * formula-safe by CsvWriter.
 */
class GenerateReportExport implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 900;

    /** @var list<int> */
    public array $backoff = [60];

    public function __construct(public readonly int $exportId)
    {
        // Long-running: its own connection, whose retry_after exceeds the timeout.
        $this->onConnection(config('competition.queues.long_connection'))->onQueue(config('competition.queues.long'));
    }

    public function handle(ReportCatalog $catalog): void
    {
        $export = ReportExport::query()->find($this->exportId);
        if ($export === null || $export->status === 'done') {
            return;
        }

        // The requester must still be allowed to see this report when it is built.
        if (! ReportCatalog::allowed($export->user, $export->report)) {
            $export->forceFill(['status' => 'failed', 'error' => 'Permission no longer held.', 'finished_at' => now()])->save();

            return;
        }

        $export->forceFill(['status' => 'running'])->save();
        $path = 'exports/'.$export->report.'-'.Str::uuid().'.csv';
        $disk = Storage::disk('local');
        $disk->makeDirectory('exports');
        $handle = fopen($disk->path($path), 'w');

        try {
            [$header, $map] = $catalog->columns($export->report);
            $csv = new CsvWriter($handle);
            $csv->write($header);

            $rows = 0;
            $catalog->query($export->report, ReportPeriod::fromArray($export->filters))->lazy(1000)->each(function ($row) use ($csv, $map, &$rows) {
                $csv->write($map($row));
                $rows++;
            });
            fclose($handle);

            $export->forceFill(['status' => 'done', 'path' => $path, 'rows' => $rows, 'finished_at' => now()])->save();
        } catch (Throwable $e) {
            is_resource($handle) && fclose($handle);
            $disk->delete($path);
            $export->forceFill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 255), 'finished_at' => now()])->save();

            throw $e;
        }
    }
}
