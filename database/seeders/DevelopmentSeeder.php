<?php

namespace Database\Seeders;

use App\Enums\QuestionDifficulty;
use App\Enums\QuestionStatus;
use App\Enums\QuizPaperStatus;
use App\Enums\UserRole;
use App\Enums\WalletType;
use App\Models\Category;
use App\Models\Question;
use App\Models\QuizPaper;
use App\Models\QuizPaperQuestion;
use App\Models\Room;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Competition\RoomEntryService;
use App\Services\Competition\RoomLifecycle;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Local/demo data only. Every room is free, every wallet is at zero, and no
 * ledger, payment, prize or payout rows are created.
 */
class DevelopmentSeeder extends Seeder
{
    public const ADMIN_EMAIL = 'admin@mcq.test';

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('DevelopmentSeeder must never run in production.');
        }

        if (User::where('email', self::ADMIN_EMAIL)->exists()) {
            $this->command?->warn('Development data already present; only ensuring staff accounts.');
            $this->ensureStaffAccounts();

            return;
        }

        DB::transaction(function () {
            $admin = $this->seedUsers();
            $this->ensureStaffAccounts();
            $paper = $this->seedQuestionBank($admin);
            $this->seedRooms($admin, $paper);
        });
    }

    /**
     * One local account per staff role (password "password", local only).
     * Idempotent, so it also upgrades databases seeded before these roles existed.
     */
    protected function ensureStaffAccounts(): void
    {
        $staff = [
            ['staff.admin@mcq.test', 'Local Site Admin', 'siteadmin', UserRole::Admin],
            ['content@mcq.test', 'Local Content Manager', 'contentmgr', UserRole::ContentManager],
            ['competitions@mcq.test', 'Local Competition Manager', 'compmgr', UserRole::CompetitionManager],
            ['finance@mcq.test', 'Local Finance Manager', 'financemgr', UserRole::FinanceManager],
        ];

        foreach ($staff as [$email, $name, $displayName, $role]) {
            if (User::withTrashed()->where('email', $email)->exists()) {
                continue;
            }

            $user = User::factory()->role($role)->create(['name' => $name, 'display_name' => $displayName, 'email' => $email]);
            Wallet::factory()->create(['type' => WalletType::User, 'user_id' => $user->id]);
        }
    }

    protected function seedUsers(): User
    {
        $admin = User::factory()->role(UserRole::SuperAdmin)->create([
            'name' => 'Local Admin',
            'display_name' => 'admin',
            'email' => self::ADMIN_EMAIL,
        ]);

        foreach (range(1, 10) as $i) {
            User::factory()->create([
                'name' => "Player {$i}",
                'display_name' => "player{$i}",
                'email' => "player{$i}@mcq.test",
            ]);
        }

        // Zero-balance wallets for every user.
        User::query()->each(function (User $user) {
            Wallet::factory()->create([
                'type' => WalletType::User,
                'user_id' => $user->id,
            ]);
        });

        return $admin;
    }

    protected function seedQuestionBank(User $admin): QuizPaper
    {
        $bank = [
            'General Knowledge' => [
                ['What is the capital of Pakistan?', ['Karachi', 'Lahore', 'Islamabad', 'Peshawar'], 3],
                ['Which is the longest river in Pakistan?', ['Jhelum', 'Indus', 'Chenab', 'Ravi'], 2],
                ['K2 is located in which mountain range?', ['Himalaya', 'Hindu Kush', 'Karakoram', 'Sulaiman'], 3],
            ],
            'Science' => [
                ['What is the chemical symbol for gold?', ['Ag', 'Au', 'Gd', 'Go'], 2],
                ['How many planets are in the Solar System?', ['7', '8', '9', '10'], 2],
                ['Which gas do plants absorb for photosynthesis?', ['Oxygen', 'Nitrogen', 'Carbon dioxide', 'Hydrogen'], 3],
            ],
            'Computer Science' => [
                ['What does HTML stand for?', ['HyperText Markup Language', 'High Transfer Machine Language', 'Hyperlink Text Mode Language', 'Home Tool Markup Language'], 1],
                ['Which data structure uses FIFO order?', ['Stack', 'Queue', 'Tree', 'Graph'], 2],
            ],
        ];

        $paper = new QuizPaper([
            'title' => 'Sample Mixed Paper',
            'description' => 'Development sample paper.',
            'status' => QuizPaperStatus::Published,
            'default_time_limit_seconds' => 20,
        ]);
        $paper->created_by = $admin->id;
        $paper->save();

        $position = 0;
        $sortOrder = 0;

        foreach ($bank as $categoryName => $questions) {
            $category = Category::factory()->create([
                'name' => $categoryName,
                'slug' => str($categoryName)->slug(),
                'sort_order' => ++$sortOrder,
            ]);

            foreach ($questions as [$body, $options, $correct]) {
                $question = new Question([
                    'category_id' => $category->id,
                    'body' => $body,
                    'difficulty' => QuestionDifficulty::Easy,
                    'status' => QuestionStatus::Active,
                ]);
                $question->created_by = $admin->id;
                $question->save();

                foreach ($options as $index => $text) {
                    $question->options()->create([
                        'body' => $text,
                        'position' => $index + 1,
                        'is_correct' => $index + 1 === $correct,
                    ]);
                }

                QuizPaperQuestion::create([
                    'quiz_paper_id' => $paper->id,
                    'question_id' => $question->id,
                    'position' => ++$position,
                ]);
            }
        }

        return $paper;
    }

    /**
     * Rooms go through the real lifecycle (validation, snapshot, audited
     * transitions) and seats through the entry service, so counts and
     * snapshots are consistent. Everything is free; no money moves.
     */
    protected function seedRooms(User $admin, QuizPaper $paper): void
    {
        $lifecycle = app(RoomLifecycle::class);
        $entries = app(RoomEntryService::class);
        $base = ['quiz_paper_id' => $paper->id, 'entry_fee_minor' => 0, 'created_by' => $admin->id];

        Room::factory()->create($base + ['name' => 'Draft Practice Room']);

        // 8 PM Pakistan time tomorrow, stored in UTC; registration opens in 12 hours.
        $scheduled = Room::factory()->create($base + [
            'name' => 'Tomorrow Evening Quiz',
            'registration_opens_at' => now()->addHours(12),
            'scheduled_start_at' => now(config('competition.display_timezone'))->addDay()->setTime(20, 0)->utc(),
        ]);
        $lifecycle->publish($admin, $scheduled);

        $fill = Room::factory()->fill(5)->create($base + [
            'name' => 'Quick Five (fills and starts)',
            'registration_closes_at' => now()->addDays(2),
        ]);
        $lifecycle->publish($admin, $fill);

        $cancelled = Room::factory()->create($base + ['name' => 'Cancelled Free Room']);
        $lifecycle->publish($admin, $cancelled);
        $lifecycle->cancel($cancelled, $admin, 'Development sample.');

        // Three players hold seats in the open fill room.
        foreach (User::where('role', UserRole::Player)->orderBy('id')->limit(3)->get() as $player) {
            $entries->join($player, $fill);
        }
    }
}
