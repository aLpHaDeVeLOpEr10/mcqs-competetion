<?php

namespace App\Services\Content;

use App\Enums\QuestionStatus;
use App\Enums\QuizPaperStatus;
use App\Exceptions\AdministrativeActionException;
use App\Exceptions\ContentValidationException;
use App\Models\Question;
use App\Models\QuizPaper;
use App\Models\QuizPaperQuestion;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Builds quiz papers. Every structural change runs in a transaction holding
 * a row lock on the paper, refuses locked (in-use) or archived papers, and
 * returns a published paper to draft so it must be validated again.
 */
class PaperBuilder
{
    public function __construct(
        protected AuditLogger $audit,
        protected PaperValidator $validator,
    ) {}

    /**
     * @param  array{title: string, description?: ?string, default_time_limit_seconds: int, target_question_count?: ?int}  $data
     */
    public function create(User $actor, array $data): QuizPaper
    {
        return DB::transaction(function () use ($actor, $data) {
            $paper = new QuizPaper($data);
            $paper->forceFill(['status' => QuizPaperStatus::Draft, 'created_by' => $actor->id, 'updated_by' => $actor->id])->save();
            $this->audit->record('paper.created', $paper, null, $this->summary($paper), actor: $actor);

            return $paper;
        });
    }

    /**
     * Title and description can always be edited. Timing and question-count
     * settings are structural: they follow the same rules as editing items.
     */
    public function update(User $actor, QuizPaper $paper, array $data): QuizPaper
    {
        $structural = ['default_time_limit_seconds', 'target_question_count'];
        $changesStructure = collect($structural)
            ->contains(fn ($key) => array_key_exists($key, $data) && $data[$key] != $paper->{$key});

        if ($changesStructure) {
            return $this->mutate($actor, $paper, 'paper.updated', fn (QuizPaper $paper) => $paper->fill($data)->save());
        }

        return DB::transaction(function () use ($actor, $paper, $data) {
            $paper = QuizPaper::query()->lockForUpdate()->findOrFail($paper->id);
            $before = $this->summary($paper);
            $paper->fill(array_intersect_key($data, array_flip(['title', 'description'])));
            $paper->updated_by = $actor->id;
            $paper->save();

            if ($paper->wasChanged(['title', 'description'])) {
                $this->audit->record('paper.updated', $paper, $before, $this->summary($paper), actor: $actor);
            }

            return $paper;
        });
    }

    /**
     * @param  list<int>  $questionIds
     */
    public function addQuestions(User $actor, QuizPaper $paper, array $questionIds): int
    {
        $added = 0;

        $this->mutate($actor, $paper, 'paper.questions_added', function (QuizPaper $paper) use ($questionIds, &$added) {
            $existing = $paper->items()->pluck('question_id')->all();
            $next = (int) $paper->items()->max('position');
            $max = (int) config('competition.papers.max_questions');

            $questions = Question::query()
                ->whereIn('id', array_unique($questionIds))
                ->where('status', '!=', QuestionStatus::Archived)
                ->whereNotIn('id', $existing)
                ->orderByRaw('FIELD(id, '.implode(',', array_map('intval', $questionIds)).')')
                ->get(['id']);

            if ($questions->isEmpty()) {
                throw new AdministrativeActionException('No new, non-archived questions were selected.');
            }

            if ($next + $questions->count() > $max) {
                throw new AdministrativeActionException("A paper can hold at most {$max} questions.");
            }

            foreach ($questions as $question) {
                QuizPaperQuestion::create(['quiz_paper_id' => $paper->id, 'question_id' => $question->id, 'position' => ++$next]);
                $added++;
            }
        }, fn () => ['question_ids' => $questionIds]);

        return $added;
    }

    public function removeItem(User $actor, QuizPaper $paper, QuizPaperQuestion $item): void
    {
        $this->mutate($actor, $paper, 'paper.question_removed', function (QuizPaper $paper) use ($item) {
            $this->ownedItem($paper, $item)->delete();
            $this->renumber($paper);
        }, fn () => ['question_id' => $item->question_id]);
    }

    /**
     * @param  array{time_limit_seconds: ?int, points: int}  $data
     */
    public function updateItem(User $actor, QuizPaper $paper, QuizPaperQuestion $item, array $data): void
    {
        $this->mutate($actor, $paper, 'paper.question_updated', function (QuizPaper $paper) use ($item, $data) {
            $this->ownedItem($paper, $item)->fill($data)->save();
        }, fn () => ['question_id' => $item->question_id] + $data);
    }

    public function move(User $actor, QuizPaper $paper, QuizPaperQuestion $item, string $direction): void
    {
        $this->mutate($actor, $paper, 'paper.question_moved', function (QuizPaper $paper) use ($item, $direction) {
            $ids = $paper->items()->orderBy('position')->pluck('id')->all();
            $index = array_search($this->ownedItem($paper, $item)->id, $ids, true);
            $swap = $direction === 'up' ? $index - 1 : $index + 1;

            if ($swap < 0 || $swap >= count($ids)) {
                return;
            }

            [$ids[$index], $ids[$swap]] = [$ids[$swap], $ids[$index]];
            $this->applyOrder($paper, $ids);
        }, fn () => ['question_id' => $item->question_id, 'direction' => $direction]);
    }

    public function publish(User $actor, QuizPaper $paper): void
    {
        DB::transaction(function () use ($actor, $paper) {
            $paper = QuizPaper::query()->lockForUpdate()->findOrFail($paper->id);

            if ($paper->status === QuizPaperStatus::Published) {
                throw new AdministrativeActionException('The paper is already published.');
            }

            if ($paper->status === QuizPaperStatus::Archived) {
                throw new AdministrativeActionException('Archived papers cannot be published. Restore it first.');
            }

            if ($problems = $this->validator->problems($paper)) {
                throw new ContentValidationException('The paper cannot be published yet.', $problems);
            }

            $paper->forceFill(['status' => QuizPaperStatus::Published, 'published_at' => now(), 'updated_by' => $actor->id])->save();
            $this->audit->record('paper.published', $paper, ['status' => 'draft'], ['status' => 'published', 'questions' => $paper->items()->count()], actor: $actor);
        });
    }

    public function unpublish(User $actor, QuizPaper $paper): void
    {
        $this->setStatus($actor, $paper, QuizPaperStatus::Draft, 'paper.unpublished', requireUnlocked: true);
    }

    public function archive(User $actor, QuizPaper $paper): void
    {
        $this->setStatus($actor, $paper, QuizPaperStatus::Archived, 'paper.archived', requireUnlocked: false);
    }

    public function restore(User $actor, QuizPaper $paper): void
    {
        $this->setStatus($actor, $paper, QuizPaperStatus::Draft, 'paper.restored', requireUnlocked: false);
    }

    public function duplicate(User $actor, QuizPaper $source): QuizPaper
    {
        return DB::transaction(function () use ($actor, $source) {
            $copy = new QuizPaper([
                'title' => mb_substr('Copy of '.$source->title, 0, 160),
                'description' => $source->description,
                'default_time_limit_seconds' => $source->default_time_limit_seconds,
                'target_question_count' => $source->target_question_count,
            ]);
            $copy->forceFill(['status' => QuizPaperStatus::Draft, 'created_by' => $actor->id, 'updated_by' => $actor->id])->save();

            foreach ($source->items()->orderBy('position')->get() as $item) {
                QuizPaperQuestion::create($item->only(['question_id', 'position', 'time_limit_seconds', 'points']) + ['quiz_paper_id' => $copy->id]);
            }

            $this->audit->record('paper.duplicated', $copy, null, ['source_paper_id' => $source->id] + $this->summary($copy), actor: $actor);

            return $copy;
        });
    }

    /**
     * Run a structural change under a lock, then audit it. A published paper
     * returns to draft so it has to pass validation again.
     */
    protected function mutate(User $actor, QuizPaper $paper, string $action, callable $change, ?callable $details = null): QuizPaper
    {
        return DB::transaction(function () use ($actor, $paper, $action, $change, $details) {
            $paper = QuizPaper::query()->lockForUpdate()->findOrFail($paper->id);
            $this->ensureEditable($paper);
            $before = $this->summary($paper);

            $change($paper);

            $wasPublished = $paper->status === QuizPaperStatus::Published;
            $paper->forceFill([
                'status' => QuizPaperStatus::Draft,
                'published_at' => $wasPublished ? null : $paper->published_at,
                'updated_by' => $actor->id,
            ])->save();

            $this->audit->record($action, $paper, $before, $this->summary($paper->refresh()), $details ? $details() : null, $actor);

            return $paper;
        });
    }

    protected function setStatus(User $actor, QuizPaper $paper, QuizPaperStatus $status, string $action, bool $requireUnlocked): void
    {
        DB::transaction(function () use ($actor, $paper, $status, $action, $requireUnlocked) {
            $paper = QuizPaper::query()->lockForUpdate()->findOrFail($paper->id);
            $previous = $paper->status;

            if ($previous === $status) {
                throw new AdministrativeActionException("The paper is already {$status->value}.");
            }

            if ($requireUnlocked && $paper->isLocked()) {
                throw new AdministrativeActionException('This paper is used by a published competition and cannot return to draft. Duplicate it instead.');
            }

            $paper->forceFill(['status' => $status, 'updated_by' => $actor->id])->save();
            $this->audit->record($action, $paper, ['status' => $previous->value], ['status' => $status->value], actor: $actor);
        });
    }

    protected function ensureEditable(QuizPaper $paper): void
    {
        if ($paper->status === QuizPaperStatus::Archived) {
            throw new AdministrativeActionException('Archived papers cannot be edited. Restore it first.');
        }

        if ($paper->isLocked()) {
            throw new AdministrativeActionException('This paper is used by a published competition, so its questions, order and timings are locked. Duplicate it to make changes.');
        }
    }

    protected function ownedItem(QuizPaper $paper, QuizPaperQuestion $item): QuizPaperQuestion
    {
        abort_unless($item->quiz_paper_id === $paper->id, 404);

        return QuizPaperQuestion::query()->lockForUpdate()->findOrFail($item->id);
    }

    protected function renumber(QuizPaper $paper): void
    {
        $this->applyOrder($paper, $paper->items()->orderBy('position')->pluck('id')->all());
    }

    /**
     * Rewrite positions 1..n in the given order. Rows are first moved to a
     * temporary range so the unique (paper, position) index never collides.
     *
     * @param  list<int>  $orderedIds
     */
    protected function applyOrder(QuizPaper $paper, array $orderedIds): void
    {
        $offset = 10000;
        DB::table('quiz_paper_questions')->where('quiz_paper_id', $paper->id)->update(['position' => DB::raw("position + {$offset}")]);

        foreach ($orderedIds as $index => $id) {
            DB::table('quiz_paper_questions')->where('id', $id)->update(['position' => $index + 1]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function summary(QuizPaper $paper): array
    {
        return [
            'title' => $paper->title,
            'status' => $paper->status?->value,
            'default_time_limit_seconds' => $paper->default_time_limit_seconds,
            'target_question_count' => $paper->target_question_count,
            'items' => $paper->items()->orderBy('position')->get(['question_id', 'time_limit_seconds', 'points'])
                ->map(fn ($i) => [$i->question_id, $i->time_limit_seconds, $i->points])->all(),
        ];
    }
}
