<?php

namespace Tests\Feature\Public;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PublicPagesTest extends TestCase
{
    use RefreshDatabase;

    public static function pages(): array
    {
        return [
            'home' => ['home', 'Think fast. Answer right.'],
            'competitions' => ['competitions.index', 'Competitions'],
            'leaderboard' => ['leaderboard', 'Leaderboard'],
            'how to play' => ['how-to-play', 'Scoring and tie-breakers'],
            'contact' => ['contact', 'Contact us'],
            'terms' => ['terms', 'Terms of use'],
            'privacy' => ['privacy', 'Privacy policy'],
            'responsible play' => ['responsible-play', 'Responsible play'],
        ];
    }

    #[DataProvider('pages')]
    public function test_public_page_renders_with_shared_layout(string $route, string $heading): void
    {
        $this->get(route($route))
            ->assertOk()
            ->assertSee($heading)
            ->assertSee('Skip to content')
            ->assertSee(route('login'), false)
            ->assertSee(route('register'), false)
            ->assertSee('Only free-entry competitions are currently offered.');
    }

    public function test_navigation_marks_the_current_page(): void
    {
        $html = $this->get(route('leaderboard'))->getContent();
        $current = fn (string $url) => '#href="'.preg_quote($url, '#').'"\s+aria-current="page"#';

        $this->assertMatchesRegularExpression($current(route('leaderboard')), $html);
        $this->assertDoesNotMatchRegularExpression($current(route('competitions.index')), $html);
    }

    public function test_legal_pages_are_marked_as_drafts(): void
    {
        foreach (['terms', 'privacy'] as $route) {
            $this->get(route($route))->assertSee('Draft — pending legal review');
        }
    }

    public function test_login_and_register_pages_render_forms_with_csrf(): void
    {
        foreach (['login', 'register'] as $route) {
            $this->get(route($route))
                ->assertOk()
                ->assertSee('type="password"', false)
                ->assertSee('name="_token"', false);
        }
    }

    public function test_home_shows_empty_state_without_fabricated_rooms_or_stats(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('No competitions scheduled right now')
            ->assertDontSee('players online');
    }

    public function test_unknown_page_uses_the_branded_404(): void
    {
        $this->get('/definitely-not-a-page')
            ->assertNotFound()
            ->assertSee('Page not found')
            ->assertSee('Browse competitions');
    }

    public function test_server_error_page_is_self_contained(): void
    {
        $html = view('errors.500')->render();

        $this->assertStringContainsString('Something went wrong', $html);
        $this->assertStringNotContainsString('/build/', $html);
    }
}
