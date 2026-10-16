<?php

namespace App\Services\Engine;

use App\Models\SessionQuestion;

/**
 * The single place that shapes a live question for players. Whitelists
 * fields explicitly, so answer keys, explanations, source ids and storage
 * paths can never leak through serialization.
 */
class QuestionPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function forPlayer(SessionQuestion $question, int $total): array
    {
        return [
            'id' => $question->id,
            'position' => $question->position,
            'total' => $total,
            'body' => $question->body,
            'image_url' => $question->image_path ? route('play.image', $question) : null,
            'points' => $question->points,
            'time_limit_seconds' => $question->time_limit_seconds,
            'opens_at' => $question->opens_at?->format('Y-m-d\TH:i:s.vP'),
            'closes_at' => $question->closes_at?->format('Y-m-d\TH:i:s.vP'),
            'options' => $question->options()->orderBy('position')->get(['id', 'position', 'body'])
                ->map(fn ($o) => ['id' => $o->id, 'label' => chr(64 + $o->position), 'body' => $o->body])
                ->all(),
        ];
    }
}
