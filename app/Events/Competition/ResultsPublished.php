<?php

namespace App\Events\Competition;

use App\Models\CompetitionSession;

/**
 * Results are available (first publication or a later correction).
 * Carries only a link; the results page is the source of truth.
 */
class ResultsPublished extends CompetitionEvent
{
    public function __construct(public readonly CompetitionSession $session, public readonly int $version)
    {
        parent::__construct($session->id);
    }

    public function broadcastAs(): string
    {
        return 'results.published';
    }

    protected function payload(): array
    {
        return [
            'version' => $this->version,
            'results_url' => route('competitions.results', $this->session->room_id),
        ];
    }
}
