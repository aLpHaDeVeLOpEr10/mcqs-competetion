<?php

namespace App\Services\Content;

use App\Enums\QuestionStatus;
use App\Exceptions\AdministrativeActionException;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Tag;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Creates and edits bank questions. Competitions never read these rows
 * directly; they use the immutable snapshot taken when they are prepared,
 * so edits here never change a live or completed competition.
 *
 * $data keys: category_id, body, explanation, image_alt, difficulty, status,
 * default_time_limit_seconds, options (ordered list of strings),
 * correct (0-based index into options), tags (free text).
 */
class QuestionService
{
    public function __construct(
        protected AuditLogger $audit,
        protected QuestionImageStore $images,
    ) {}

    public function create(User $actor, array $data, ?UploadedFile $image = null): Question
    {
        $imagePath = $image ? $this->images->store($image) : null;

        try {
            return DB::transaction(function () use ($actor, $data, $imagePath) {
                $question = new Question($this->attributes($data));
                $question->forceFill([
                    'created_by' => $actor->id,
                    'updated_by' => $actor->id,
                    'image_path' => $imagePath,
                ])->save();

                $this->syncOptions($question, $data['options'], $data['correct']);
                $question->tags()->sync(Tag::idsFromList($data['tags'] ?? ''));

                $this->audit->record('question.created', $question, null, $this->summary($question), actor: $actor);

                return $question;
            });
        } catch (\Throwable $e) {
            $this->images->delete($imagePath);
            throw $e;
        }
    }

    public function update(User $actor, Question $question, array $data, ?UploadedFile $image = null, bool $removeImage = false): Question
    {
        $newImage = $image ? $this->images->store($image) : null;
        $oldImage = null;

        try {
            $question = DB::transaction(function () use ($actor, $question, $data, $newImage, $removeImage, &$oldImage) {
                $question = Question::query()->lockForUpdate()->findOrFail($question->id);
                $before = $this->summary($question);

                if ($question->isArchived()) {
                    throw new AdministrativeActionException('Archived questions cannot be edited. Restore it first.');
                }

                $question->fill($this->attributes($data));
                $question->updated_by = $actor->id;

                if ($newImage || $removeImage) {
                    $oldImage = $question->image_path;
                    $question->image_path = $newImage;
                }

                $question->save();
                $this->syncOptions($question, $data['options'], $data['correct']);
                $question->tags()->sync(Tag::idsFromList($data['tags'] ?? ''));

                $after = $this->summary($question->refresh());
                if ($after !== $before) {
                    $this->audit->record('question.updated', $question, $before, $after, actor: $actor);
                }

                return $question;
            });
        } catch (\Throwable $e) {
            $this->images->delete($newImage);
            throw $e;
        }

        // Only remove the old file once the database change is committed.
        $this->images->delete($oldImage);

        return $question;
    }

    public function archive(User $actor, Question $question): void
    {
        $this->changeStatus($actor, $question, QuestionStatus::Archived, 'question.archived');
    }

    public function restore(User $actor, Question $question): void
    {
        $this->changeStatus($actor, $question, QuestionStatus::Draft, 'question.restored');
    }

    protected function changeStatus(User $actor, Question $question, QuestionStatus $status, string $action): void
    {
        DB::transaction(function () use ($actor, $question, $status, $action) {
            $question = Question::query()->lockForUpdate()->findOrFail($question->id);
            $previous = $question->status;

            if ($previous === $status) {
                throw new AdministrativeActionException("The question is already {$status->value}.");
            }

            $question->forceFill(['status' => $status, 'updated_by' => $actor->id])->save();
            $this->audit->record($action, $question, ['status' => $previous->value], ['status' => $status->value], actor: $actor);
        });
    }

    /**
     * Update options in place by position, so option ids stay stable.
     * Correctness is cleared before being set, keeping the "at most one
     * correct option" unique index satisfied at every step.
     *
     * @param  list<string>  $bodies
     */
    protected function syncOptions(Question $question, array $bodies, int $correct): void
    {
        $existing = $question->options()->get()->keyBy('position');

        $question->options()->update(['is_correct' => false]);

        foreach (array_values($bodies) as $index => $body) {
            $position = $index + 1;
            $option = $existing->get($position) ?? new QuestionOption(['question_id' => $question->id, 'position' => $position]);
            $option->body = $body;
            $option->is_correct = false;
            $option->save();
        }

        $question->options()->where('position', '>', count($bodies))->delete();
        $question->options()->where('position', $correct + 1)->update(['is_correct' => true]);
        $question->unsetRelation('options');
    }

    /**
     * @return array<string, mixed>
     */
    protected function attributes(array $data): array
    {
        return [
            'category_id' => $data['category_id'],
            'body' => $data['body'],
            'explanation' => $data['explanation'] ?? null,
            'image_alt' => $data['image_alt'] ?? null,
            'difficulty' => $data['difficulty'],
            'status' => $data['status'],
            'default_time_limit_seconds' => $data['default_time_limit_seconds'] ?? null,
        ];
    }

    /**
     * Audit-friendly description of a question's content.
     *
     * @return array<string, mixed>
     */
    public function summary(Question $question): array
    {
        $options = $question->options()->get();

        return [
            'category_id' => $question->category_id,
            'body' => $question->body,
            'explanation' => $question->explanation,
            'difficulty' => $question->difficulty?->value,
            'status' => $question->status?->value,
            'default_time_limit_seconds' => $question->default_time_limit_seconds,
            'has_image' => $question->image_path !== null,
            'options' => $options->pluck('body')->all(),
            'correct_position' => $options->firstWhere('is_correct', true)?->position,
            'tags' => $question->tags()->pluck('name')->all(),
        ];
    }
}
