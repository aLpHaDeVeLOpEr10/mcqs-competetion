<?php

namespace App\Services\Results;

use App\Enums\ParticipantStatus;
use App\Enums\PayoutStatus;
use App\Enums\PrizeDistributionStatus;
use App\Enums\TieBreakPolicy;
use App\Exceptions\AdministrativeActionException;
use App\Models\CompetitionSession;
use App\Models\ParticipantResult;
use App\Models\PrizeDistribution;
use App\Models\PrizePayout;
use App\Models\ResultVersion;
use App\Models\Room;
use App\Models\RoomParticipant;
use App\Models\User;
use App\Services\Prizes\PrizePoolCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Builds a results version from persisted data only and stores it.
 * Callers (ResultsFinalizer, ResultCorrectionService) hold row locks on the
 * room and session. Inputs:
 *  - seats that played (finished / playing / disqualified);
 *  - snapshot questions with their authoritative window (opens_at = actual
 *    delivery, deadline = closes_at + the grace frozen in the session config);
 *  - answers, with correctness read from the snapshot options (not from the
 *    flag stored at submission), and the server receipt time.
 * The frozen session configuration (scoring policy, tie-break policy, prize
 * tiers) is used, never the current room settings.
 */
class ResultsCalculator
{
    public function __construct(
        protected ScoringPolicy $scoring,
        protected PrizeCalculator $prizes,
        protected PrizePoolCalculator $pools,
    ) {}

    public function calculate(Room $room, CompetitionSession $session, string $reason, ?User $actor): ResultVersion
    {
        $config = $session->config ?? [];
        $policy = $config['scoring_policy'] ?? ScoringPolicy::VERSION;
        if ($policy !== ScoringPolicy::VERSION) {
            throw new AdministrativeActionException("Unknown scoring policy [{$policy}].");
        }

        $tieBreak = TieBreakPolicy::from($config['tie_break_policy'] ?? $room->tie_break_policy->value);
        $grace = (int) ($config['answer_grace_ms'] ?? config('competition.engine.answer_grace_ms'));
        // Frozen rules; sessions prepared before percentage prizes existed only froze fixed tiers.
        $rules = $config['prize_rules'] ?? [
            'mode' => 'fixed',
            'tiers' => $config['prize_tiers'] ?? $room->prizeTiers()->orderBy('rank_from')->get()
                ->map(fn ($t) => [$t->rank_from, $t->rank_to, $t->amount_minor])->all(),
        ];
        $pool = $this->pools->calculate($session->room_id, $rules);
        $tiers = $pool['tiers'];

        $inputs = $this->inputs($session, $grace);

        $scored = $this->scoring->score(
            $inputs['participants'],
            $inputs['questions'],
            $inputs['answers'],
            $session->started_at?->getTimestampMs() ?? 0,
            $tieBreak,
        );

        $minCorrect = (int) config('competition.prizes.min_correct_answers');
        $ranked = array_values(array_map(fn ($row) => [
            'participant_id' => $row['participant_id'],
            'rank' => $row['rank'],
            'eligible' => $row['correct_count'] >= $minCorrect,
        ], array_filter($scored, fn ($row) => $row['rank'] !== null)));

        $allocation = $this->prizes->allocate($tiers, $ranked);

        $version = (int) ResultVersion::query()->where('competition_session_id', $session->id)->max('version') + 1;
        $this->assertNoPaidPayouts($session);

        $this->storeResults($session, $scored, $allocation['awards'], $minCorrect, $version);
        $this->storePrizes($room, $session, $allocation, $pool, $scored, $version);

        $canonicalInputs = $inputs + ['prize_rules' => $rules, 'prize_pool' => array_diff_key($pool, ['rules' => 1]), 'tiers' => $tiers, 'grace_ms' => $grace, 'tie_break_policy' => $tieBreak->value, 'min_correct' => $minCorrect];

        return tap((new ResultVersion)->forceFill([
            'competition_session_id' => $session->id,
            'version' => $version,
            'scoring_policy' => $policy,
            'tie_break_policy' => $tieBreak->value,
            'reason' => mb_substr($reason, 0, 255),
            'inputs_hash' => hash('sha256', json_encode($canonicalInputs)),
            'inputs' => $canonicalInputs,
            'results' => $scored,
            'prizes' => $allocation + ['pool' => array_diff_key($pool, ['rules' => 1])],
            'created_by' => $actor?->id,
        ]))->save();
    }

    /**
     * @return array{participants: list<array>, questions: array<int, array>, answers: list<array>}
     */
    protected function inputs(CompetitionSession $session, int $graceMs): array
    {
        $participants = RoomParticipant::query()
            ->where('room_id', $session->room_id)
            ->whereIn('status', [ParticipantStatus::Playing, ParticipantStatus::Finished, ParticipantStatus::Disqualified])
            ->orderBy('id')
            ->get(['id', 'status', 'disqualification_reason'])
            ->map(fn ($seat) => [
                'id' => $seat->id,
                'excluded' => $seat->status === ParticipantStatus::Disqualified
                    ? 'Disqualified'.($seat->disqualification_reason ? ': '.$seat->disqualification_reason : '')
                    : null,
            ])->all();

        $questions = $session->questions()->orderBy('position')->get()
            ->mapWithKeys(fn ($q) => [$q->id => [
                'position' => $q->position,
                'opens_at_ms' => $q->delivered_at ? $q->opens_at?->getTimestampMs() : null,
                'deadline_ms' => $q->delivered_at && $q->closes_at ? $q->closes_at->getTimestampMs() + $graceMs : null,
                'voided' => $q->voided_at !== null,
            ]])->all();

        $answers = DB::table('answer_submissions as a')
            ->join('session_question_options as o', 'o.id', '=', 'a.session_question_option_id')
            ->join('session_questions as q', 'q.id', '=', 'a.session_question_id')
            ->where('q.competition_session_id', $session->id)
            ->orderBy('a.id')
            ->get(['a.id', 'a.room_participant_id', 'a.session_question_id', 'a.received_at', 'o.is_correct'])
            ->map(fn ($a) => [
                'id' => $a->id,
                'participant_id' => $a->room_participant_id,
                'question_id' => $a->session_question_id,
                'received_ms' => CarbonImmutable::parse($a->received_at)->getTimestampMs(),
                'is_correct' => (bool) $a->is_correct,
            ])->all();

        return ['participants' => $participants, 'questions' => $questions, 'answers' => $answers];
    }

    protected function storeResults(CompetitionSession $session, array $scored, array $awards, int $minCorrect, int $version): void
    {
        foreach ($scored as $row) {
            $result = ParticipantResult::query()->where('room_participant_id', $row['participant_id'])->first()
                ?? (new ParticipantResult)->forceFill(['room_participant_id' => $row['participant_id']]);
            $eligible = $row['rank'] !== null && $row['correct_count'] >= $minCorrect;

            $result->forceFill([
                'competition_session_id' => $session->id,
                'score' => $row['score'],
                'correct_count' => $row['correct_count'],
                'answered_count' => $row['answered_count'],
                'correct_response_time_ms' => $row['correct_time_ms'],
                'last_correct_elapsed_ms' => $row['last_correct_elapsed_ms'],
                'rank' => $row['rank'],
                'is_winner' => $row['rank'] === 1 && $eligible,
                'prize_eligible' => $eligible,
                'prize_amount_minor' => $awards[$row['participant_id']] ?? 0,
                'excluded_reason' => $row['excluded'],
                'result_version' => $version,
                'computed_at' => now(),
            ])->save();
        }
    }

    /**
     * Records prize ENTITLEMENTS with their audit snapshot. Nothing is paid here:
     * PrizeDistributionService credits wallets once the payout conditions hold.
     *
     * @param  array<string, mixed>  $pool  PrizePoolCalculator result
     * @param  list<array<string, mixed>>  $scored
     */
    protected function storePrizes(Room $room, CompetitionSession $session, array $allocation, array $pool, array $scored, int $version): void
    {
        $awarded = array_sum($allocation['awards']);
        $distribution = PrizeDistribution::query()->where('competition_session_id', $session->id)->first()
            ?? (new PrizeDistribution)->forceFill(['competition_session_id' => $session->id]);
        $distribution->forceFill([
            'status' => PrizeDistributionStatus::Calculated,
            'total_amount_minor' => $awarded,
            'currency' => $room->currency,
            'prize_mode' => $pool['mode'],
            'gross_entry_fees_minor' => $pool['gross_entry_fees_minor'],
            'refunds_minor' => $pool['refunds_minor'],
            'net_entry_fees_minor' => $pool['net_entry_fees_minor'],
            'platform_amount_minor' => $pool['platform_amount_minor'],
            'prize_pool_minor' => $pool['prize_pool_minor'],
            'unallocated_minor' => $pool['prize_pool_minor'] - $awarded,
            'calculation' => [
                'result_version' => $version,
                'rules' => $pool['rules'],
                'pool' => array_diff_key($pool, ['rules' => 1, 'tiers' => 1]),
                'resolved_tiers' => $pool['tiers'],
                'configured_total_minor' => $allocation['total_minor'],
                'unallocated_minor' => $pool['prize_pool_minor'] - $awarded,
                'lines' => $allocation['lines'],
                'rounding' => 'Percentages: floor per position. Ties: the tied positions are pooled and split, floor per player. Remainders are unallocated.',
            ],
            'calculated_at' => now(),
            'approved_by' => null,
            'approved_at' => null,
            'hold_reason' => null,
        ])->save();

        $payoutsEnabled = (bool) config('competition.prizes.payouts_enabled');
        $rows = collect($scored)->keyBy('participant_id');
        $lines = collect($allocation['lines'])->filter(fn ($line) => isset($line['rank']))->keyBy('rank');
        $positionAmounts = [];
        foreach ($pool['tiers'] as [$from, $to, $amount]) {
            for ($p = $from; $p <= $to; $p++) {
                $positionAmounts[$p] = $amount;
            }
        }

        foreach ($allocation['awards'] as $participantId => $amount) {
            $row = $rows[$participantId];
            $line = $lines[$row['rank']];
            $payout = PrizePayout::query()->where('room_participant_id', $participantId)->first()
                ?? (new PrizePayout)->forceFill(['room_participant_id' => $participantId]);

            // Paid, processing and reversed payouts are never rewritten (a trigger also blocks it).
            if ($payout->exists && ! in_array($payout->status, [...PayoutStatus::recalculable(), PayoutStatus::Cancelled], true)) {
                continue;
            }

            [$first, $last] = $line['positions'];
            $payout->forceFill([
                'prize_distribution_id' => $distribution->id,
                'rank' => $row['rank'],
                'result_version' => $version,
                'score' => $row['score'],
                'correct_count' => $row['correct_count'],
                'timing_ms' => $row['correct_time_ms'],
                'rule' => [
                    'mode' => $pool['mode'],
                    'positions' => [$first, $last],
                    'position_amounts_minor' => array_map(fn ($p) => $positionAmounts[$p] ?? 0, range($first, $last)),
                    'tied_players' => $line['players'],
                    'group_pool_minor' => $line['pool_minor'],
                    'each_minor' => $line['each_minor'],
                    'group_remainder_minor' => $line['unallocated_minor'],
                    'tiers' => array_values(array_filter($pool['rules']['tiers'] ?? [], fn ($t) => $t[1] >= $first && $t[0] <= $last)),
                ],
                'amount_minor' => $amount,
                'tax_withheld_minor' => 0,
                'net_amount_minor' => null,
                'status' => $payoutsEnabled ? PayoutStatus::Pending : PayoutStatus::Held,
                'status_reason' => $payoutsEnabled ? null : 'Prize payouts are not enabled on this platform.',
                'approved_by' => null,
                'approved_at' => null,
                'held_at' => $payoutsEnabled ? null : now(),
                'failed_at' => null,
                'idempotency_key' => "prize:{$session->id}:{$participantId}",
            ])->save();
        }

        // Entitlements removed by a correction are cancelled, never deleted.
        PrizePayout::query()
            ->where('prize_distribution_id', $distribution->id)
            ->whereNotIn('room_participant_id', array_keys($allocation['awards']) ?: [0])
            ->whereIn('status', PayoutStatus::recalculable())
            ->update(['status' => PayoutStatus::Cancelled->value, 'status_reason' => "No longer entitled after results version {$version}."]);
    }

    protected function assertNoPaidPayouts(CompetitionSession $session): void
    {
        $settled = PrizePayout::query()
            ->whereHas('distribution', fn ($q) => $q->where('competition_session_id', $session->id))
            ->whereIn('status', [PayoutStatus::Paid, PayoutStatus::Processing])
            ->exists();

        if ($settled) {
            throw new AdministrativeActionException('Prizes for this competition are being or have been paid, so results can no longer be recalculated. Reverse the paid prizes first.');
        }
    }
}
