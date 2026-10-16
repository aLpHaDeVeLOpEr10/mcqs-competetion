<?php

namespace App\Console\Commands;

use App\Services\Engine\CompetitionRecovery;
use Illuminate\Console\Command;

/**
 * Reconciles overdue or inconsistent competitions from persisted timestamps
 * (see CompetitionRecovery). Safe to run repeatedly and alongside workers.
 * Exits non-zero when something could not be repaired or needs an operator.
 */
class CompetitionsRecover extends Command
{
    protected $signature = 'competitions:recover {--dry-run : Report only; change nothing}';

    protected $description = 'Find and reconcile overdue or inconsistent competitions (never fabricates answers or results)';

    public function handle(CompetitionRecovery $recovery): int
    {
        $repair = ! $this->option('dry-run');
        $issues = $recovery->run($repair);

        if ($issues === []) {
            $this->info('Nothing to recover: every competition is consistent with its timestamps.');

            return self::SUCCESS;
        }

        $this->table(['Check', 'Subject', 'Detail', 'Action'], array_map('array_values', $issues));

        $unresolved = array_filter($issues, fn ($i) => str_starts_with($i['action'], 'failed') || str_starts_with($i['action'], 'reported'));
        if ($unresolved !== []) {
            $this->error(count($unresolved).' issue(s) need attention (see docs/OPERATIONS.md).');

            return self::FAILURE;
        }

        $this->info(count($issues).' issue(s) '.($repair ? 'reconciled.' : 'found (dry run, nothing changed).'));

        return self::SUCCESS;
    }
}
