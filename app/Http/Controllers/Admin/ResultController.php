<?php

namespace App\Http\Controllers\Admin;

use App\Enums\DisputeStatus;
use App\Enums\SessionStatus;
use App\Exceptions\AdministrativeActionException;
use App\Http\Controllers\Controller;
use App\Models\CompetitionSession;
use App\Models\ResultDispute;
use App\Models\RoomParticipant;
use App\Models\SessionQuestion;
use App\Services\Results\DisputeService;
use App\Services\Results\ResultCorrectionService;
use App\Services\Results\ResultsFinalizer;
use App\Support\QueryFilters;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Results are produced by ResultsFinalizer and are never edited directly.
 * Corrections recalculate everything as a new, audited result version.
 */
class ResultController extends Controller
{
    public function index(Request $request): View
    {
        $filters = QueryFilters::from($request, [
            'status' => [Rule::enum(SessionStatus::class)],
            'published' => [Rule::in(['yes', 'no'])],
        ]);

        $query = CompetitionSession::query()->with('room:id,name,status')->withCount([
            'results', 'disputes as open_disputes_count' => fn ($q) => $q->where('status', DisputeStatus::Open),
        ]);
        $query->when($filters->get('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($filters->get('published') === 'yes', fn ($q) => $q->whereNotNull('results_published_at'))
            ->when($filters->get('published') === 'no', fn ($q) => $q->whereNull('results_published_at'));

        return view('admin.results.index', [
            'sessions' => $query->latest('id')->paginate(25)->withQueryString(),
            'filters' => $filters,
        ]);
    }

    public function show(CompetitionSession $session): View
    {
        return view('admin.results.show', [
            'session' => $session->load(['room:id,name,status,currency', 'prizeDistribution.payouts']),
            'ranking' => $session->results()->with('participant.user:id,name,display_name')
                ->orderByRaw('`rank` IS NULL')->orderBy('rank')->orderBy('id')->get(),
            'versions' => $session->resultVersions()->with('creator:id,name')->get(['id', 'competition_session_id', 'version', 'scoring_policy', 'tie_break_policy', 'reason', 'inputs_hash', 'created_by', 'created_at']),
            'questions' => $session->questions()->orderBy('position')->get(['id', 'position', 'body', 'voided_at', 'void_reason']),
            'disputes' => $session->disputes()->with(['raiser:id,name,display_name', 'resolver:id,name'])->latest('id')->get(),
        ]);
    }

    public function finalize(Request $request, CompetitionSession $session, ResultsFinalizer $finalizer): RedirectResponse
    {
        $version = $finalizer->finalize($session->id, $request->user());

        return back()->with($version ? 'success' : 'error', $version ? "Results finalized (version {$version->version})." : 'This competition is not ready to be finalized.');
    }

    public function publish(Request $request, CompetitionSession $session, ResultsFinalizer $finalizer): RedirectResponse
    {
        $finalizer->publish($session->id, $request->user());

        return back()->with('success', 'Results published.');
    }

    public function voidQuestion(Request $request, CompetitionSession $session, ResultCorrectionService $corrections): RedirectResponse
    {
        $data = $request->validateWithBag('void', [
            'session_question_id' => ['required', 'integer', Rule::exists('session_questions', 'id')->where('competition_session_id', $session->id)],
            'reason' => ['required', 'string', 'min:5', 'max:255'],
            'confirm' => ['accepted'],
            'dispute_id' => ['nullable', 'integer', Rule::exists('result_disputes', 'id')->where('competition_session_id', $session->id)],
        ]);

        return $this->run(fn () => $corrections->voidQuestion($request->user(), $session,
            SessionQuestion::query()->findOrFail($data['session_question_id']), $data['reason'], $this->dispute($data)));
    }

    public function disqualify(Request $request, CompetitionSession $session, ResultCorrectionService $corrections): RedirectResponse
    {
        $data = $request->validateWithBag('disqualify', [
            'room_participant_id' => ['required', 'integer', Rule::exists('room_participants', 'id')->where('room_id', $session->room_id)],
            'reason' => ['required', 'string', 'min:5', 'max:255'],
            'confirm' => ['accepted'],
            'dispute_id' => ['nullable', 'integer', Rule::exists('result_disputes', 'id')->where('competition_session_id', $session->id)],
        ]);

        return $this->run(fn () => $corrections->disqualify($request->user(), $session,
            RoomParticipant::query()->findOrFail($data['room_participant_id']), $data['reason'], $this->dispute($data)));
    }

    public function rejectDispute(Request $request, ResultDispute $dispute, DisputeService $disputes): RedirectResponse
    {
        $data = $request->validateWithBag('dispute', ['resolution' => ['required', 'string', 'min:5', 'max:2000']]);

        try {
            $disputes->reject($request->user(), $dispute, $data['resolution']);
        } catch (AdministrativeActionException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Dispute rejected. The player can see your reply.');
    }

    protected function run(callable $correction): RedirectResponse
    {
        try {
            $version = $correction();
        } catch (AdministrativeActionException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Results recalculated as version {$version->version}. The previous version is kept.");
    }

    protected function dispute(array $data): ?ResultDispute
    {
        return isset($data['dispute_id']) ? ResultDispute::query()->find($data['dispute_id']) : null;
    }
}
