<?php

namespace App\Services\Content;

use App\Enums\QuestionDifficulty;
use App\Enums\QuestionStatus;
use App\Exceptions\AdministrativeActionException;
use App\Models\Category;
use App\Models\Question;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SplFileObject;

/**
 * Two-step CSV import: preview() parses and validates every row and keeps
 * the result server-side under a random token; commit() creates the valid,
 * non-duplicate rows atomically. The file is only ever read as text: no
 * paths from the file are used, nothing is executed, and cells that look
 * like spreadsheet formulas are rejected.
 */
class QuestionCsvImporter
{
    public const REQUIRED = ['category', 'question', 'option_a', 'option_b', 'correct'];

    public const OPTIONAL = ['option_c', 'option_d', 'option_e', 'option_f', 'difficulty', 'explanation', 'tags', 'time_limit', 'status'];

    protected const OPTION_COLUMNS = ['option_a', 'option_b', 'option_c', 'option_d', 'option_e', 'option_f'];

    protected const TTL_SECONDS = 1800;

    public function __construct(
        protected QuestionService $questions,
        protected AuditLogger $audit,
    ) {}

    /**
     * @return array{token: string, file_errors: list<string>, rows: list<array>, counts: array{valid: int, invalid: int, duplicate: int}}
     */
    public function preview(UploadedFile $file, User $user): array
    {
        [$header, $records, $fileErrors] = $this->read($file);

        $rows = [];
        if ($fileErrors === []) {
            $categories = $this->categoryLookup();
            $seen = [];

            foreach ($records as $line => $cells) {
                $rows[] = $this->validateRow($line, array_combine($header, array_pad($cells, count($header), '')), $categories, $seen);
            }

            // Duplicates of questions already in the bank (archived ones included).
            $hashes = collect($rows)->where('status', 'valid')->pluck('hash')->all();
            $existing = Question::withTrashed()->whereIn('body_hash', $hashes)->pluck('body_hash')->flip();
            foreach ($rows as &$row) {
                if ($row['status'] === 'valid' && isset($existing[$row['hash']])) {
                    $row['status'] = 'duplicate';
                    $row['errors'][] = 'Already in the question bank.';
                }
            }
            unset($row);
        }

        $result = [
            'token' => Str::random(40),
            'file_name' => mb_substr($file->getClientOriginalName(), 0, 120),
            'file_errors' => $fileErrors,
            'rows' => $rows,
            'counts' => [
                'valid' => collect($rows)->where('status', 'valid')->count(),
                'invalid' => collect($rows)->where('status', 'invalid')->count(),
                'duplicate' => collect($rows)->where('status', 'duplicate')->count(),
            ],
        ];

        Cache::put($this->cacheKey($result['token']), ['user_id' => $user->id] + $result, self::TTL_SECONDS);

        return $result;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $token, User $user): ?array
    {
        $preview = Cache::get($this->cacheKey($token));

        return $preview !== null && $preview['user_id'] === $user->id ? $preview : null;
    }

    /**
     * @return array{created: int, skipped: int}
     */
    public function commit(string $token, User $user): array
    {
        // An atomic lock plus pulling the preview means a token can be committed only once.
        $lock = Cache::lock('question-import-commit:'.$token, 60);

        if (! $lock->get()) {
            throw new AdministrativeActionException('This import is already being processed.');
        }

        try {
            $preview = $this->find($token, $user);

            if ($preview === null) {
                throw new AdministrativeActionException('This import preview has expired, was already imported, or does not belong to you. Upload the file again.');
            }

            $result = $this->createRows($preview, $user);

            // Forget only after success, so a failed import can be retried.
            Cache::forget($this->cacheKey($token));

            return $result;
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{created: int, skipped: int}
     */
    protected function createRows(array $preview, User $user): array
    {
        $valid = array_values(array_filter($preview['rows'], fn ($row) => $row['status'] === 'valid'));

        return DB::transaction(function () use ($valid, $user, $preview) {
            $created = 0;

            foreach ($valid as $row) {
                // Re-check at commit time in case the bank changed since the preview.
                if (Question::withTrashed()->where('body_hash', $row['hash'])->exists()) {
                    continue;
                }

                $this->questions->create($user, $row['data']);
                $created++;
            }

            $skipped = count($preview['rows']) - $created;
            $this->audit->record('questions.imported', null, null, [
                'file' => $preview['file_name'],
                'created' => $created,
                'skipped' => $skipped,
            ], actor: $user);

            return ['created' => $created, 'skipped' => $skipped];
        });
    }

    /**
     * Read the CSV into header + records keyed by line number.
     *
     * @return array{0: list<string>, 1: array<int, list<string>>, 2: list<string>}
     */
    protected function read(UploadedFile $file): array
    {
        $csv = new SplFileObject($file->getRealPath());
        $firstLine = (string) $csv->fgets();
        $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
        $csv->rewind();
        $csv->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::READ_AHEAD);
        $csv->setCsvControl($delimiter, '"', '');

        $header = null;
        $records = [];
        $max = (int) config('competition.questions.import_max_rows');

        foreach ($csv as $index => $cells) {
            if (! is_array($cells) || $cells === [null]) {
                continue;
            }

            $cells = array_map(fn ($cell) => $this->cleanCell((string) $cell), $cells);

            if ($header === null) {
                $header = array_map(fn ($h) => Str::snake(Str::lower(preg_replace('/^\x{FEFF}/u', '', $h))), $cells);
                $missing = array_diff(self::REQUIRED, $header);
                $unknown = array_diff($header, [...self::REQUIRED, ...self::OPTIONAL]);
                $errors = [];

                if ($missing !== []) {
                    $errors[] = 'Missing required column(s): '.implode(', ', $missing).'.';
                }
                if ($unknown !== []) {
                    $errors[] = 'Unknown column(s): '.implode(', ', $unknown).'. Use the sample file as a template.';
                }
                if (count($header) !== count(array_unique($header))) {
                    $errors[] = 'Each column may appear only once.';
                }
                if ($errors !== []) {
                    return [$header, [], $errors];
                }

                continue;
            }

            if (implode('', $cells) === '') {
                continue;
            }

            if (count($records) >= $max) {
                return [$header, [], ["The file has more than {$max} question rows. Split it into smaller files."]];
            }

            $records[$index + 1] = $cells;
        }

        if ($header === null) {
            return [[], [], ['The file is empty.']];
        }

        if ($records === []) {
            return [$header, [], ['The file has a header but no question rows.']];
        }

        return [$header, $records, []];
    }

    /**
     * @param  array<string, string>  $cells
     * @param  array<string, int>  $categories
     * @param  array<string, int>  $seen  body hash => first line
     */
    protected function validateRow(int $line, array $cells, array $categories, array &$seen): array
    {
        $errors = [];

        foreach ($cells as $column => $value) {
            if ($this->looksLikeFormula($value)) {
                $errors[] = "Column {$column} starts with a spreadsheet formula character; formulas are not allowed.";
            }
        }

        $categoryId = $categories[Str::lower($cells['category'])] ?? null;
        if ($categoryId === null) {
            $errors[] = 'Unknown or inactive category "'.Str::limit($cells['category'], 40).'".';
        }

        $body = Str::squish($cells['question']);
        if ($body === '' || mb_strlen($body) > 2000) {
            $errors[] = 'Question text is required (max 2000 characters).';
        }

        // Options: keep their column letters so "correct" maps unambiguously.
        $letters = [];
        foreach (self::OPTION_COLUMNS as $i => $column) {
            $text = Str::squish($cells[$column] ?? '');
            if ($text !== '') {
                if (mb_strlen($text) > 500) {
                    $errors[] = "{$column} is longer than 500 characters.";
                }
                $letters[chr(65 + $i)] = $text;
            }
        }

        $min = (int) config('competition.questions.min_options');
        if (count($letters) < $min) {
            $errors[] = "At least {$min} options are required.";
        }

        if (count(array_unique(array_map('mb_strtolower', $letters))) !== count($letters)) {
            $errors[] = 'Options must all be different.';
        }

        $expected = array_slice(array_keys($letters), 0, count($letters));
        if ($expected !== array_slice(range('A', 'F'), 0, count($letters))) {
            $errors[] = 'Options must be filled in order with no gaps (A, B, C…).';
        }

        $correct = Str::upper(trim($cells['correct']));
        if (! isset($letters[$correct])) {
            $errors[] = 'Correct answer must be the letter of a filled option (e.g. "B").';
        }

        $difficulty = Str::lower(trim($cells['difficulty'] ?? '')) ?: QuestionDifficulty::Medium->value;
        if (QuestionDifficulty::tryFrom($difficulty) === null) {
            $errors[] = 'Difficulty must be easy, medium or hard.';
        }

        $status = Str::lower(trim($cells['status'] ?? '')) ?: QuestionStatus::Draft->value;
        if (! in_array($status, [QuestionStatus::Draft->value, QuestionStatus::Active->value], true)) {
            $errors[] = 'Status must be draft or active.';
        }

        $time = trim($cells['time_limit'] ?? '');
        $minTime = (int) config('competition.questions.min_time_limit');
        $maxTime = (int) config('competition.questions.max_time_limit');
        if ($time !== '' && (! ctype_digit($time) || (int) $time < $minTime || (int) $time > $maxTime)) {
            $errors[] = "Time limit must be a whole number of seconds between {$minTime} and {$maxTime}.";
        }

        $explanation = Str::squish($cells['explanation'] ?? '');
        if (mb_strlen($explanation) > 2000) {
            $errors[] = 'Explanation is longer than 2000 characters.';
        }

        $hash = Question::hashBody($body);
        $duplicateOf = $body !== '' ? ($seen[$hash] ?? null) : null;

        $rowStatus = $errors !== [] ? 'invalid' : ($duplicateOf !== null ? 'duplicate' : 'valid');
        if ($duplicateOf !== null && $errors === []) {
            $errors[] = "Duplicate of line {$duplicateOf} in this file.";
        }
        if ($body !== '' && $duplicateOf === null) {
            $seen[$hash] = $line;
        }

        return [
            'line' => $line,
            'status' => $rowStatus,
            'errors' => $errors,
            'hash' => $hash,
            'display' => ['category' => $cells['category'], 'question' => Str::limit($body, 120), 'options' => count($letters), 'correct' => $correct],
            'data' => $rowStatus === 'valid' ? [
                'category_id' => $categoryId,
                'body' => $body,
                'explanation' => $explanation !== '' ? $explanation : null,
                'difficulty' => $difficulty,
                'status' => $status,
                'default_time_limit_seconds' => $time !== '' ? (int) $time : null,
                'options' => array_values($letters),
                'correct' => array_search($correct, array_keys($letters), true),
                'tags' => $cells['tags'] ?? '',
            ] : null,
        ];
    }

    /**
     * @return array<string, int> lower-cased name and slug => id
     */
    protected function categoryLookup(): array
    {
        $map = [];
        foreach (Category::query()->where('is_active', true)->get(['id', 'name', 'slug']) as $category) {
            $map[Str::lower($category->name)] = $category->id;
            $map[Str::lower($category->slug)] = $category->id;
        }

        return $map;
    }

    /**
     * Characters that make spreadsheet software evaluate a cell. Plain
     * signed numbers such as "-5" are allowed.
     */
    protected function looksLikeFormula(string $value): bool
    {
        if ($value === '' || ! in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return false;
        }

        return ! is_numeric(trim($value));
    }

    /**
     * Normalize encoding (Excel often saves Windows-1252) and drop control
     * characters other than tab and newlines.
     */
    protected function cleanCell(string $value): string
    {
        if (! mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
        }

        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
    }

    protected function cacheKey(string $token): string
    {
        return 'question-import:'.$token;
    }
}
