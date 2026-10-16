<?php

namespace Tests\Feature\Public;

use App\Mail\ContactMessageReceived;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ContactTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['competition.contact.recipient' => 'support@example.test']);
        Mail::fake();
    }

    protected function validPayload(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Ayesha',
            'email' => 'ayesha@example.test',
            'topic' => 'competition',
            'message' => 'I have a question about last night\'s results.',
        ];
    }

    public function test_valid_message_is_sent_to_the_configured_recipient(): void
    {
        $this->from(route('contact'))
            ->post(route('contact.store'), $this->validPayload())
            ->assertRedirect(route('contact'))
            ->assertSessionHas('success');

        Mail::assertSent(ContactMessageReceived::class, function (ContactMessageReceived $mail) {
            return $mail->hasTo('support@example.test')
                && $mail->hasReplyTo('ayesha@example.test')
                && $mail->topicLabel === 'A competition or result';
        });
    }

    public function test_validation_errors_are_shown_and_input_is_kept(): void
    {
        $this->from(route('contact'))
            ->post(route('contact.store'), ['name' => 'Ayesha', 'email' => 'not-an-email', 'topic' => 'bogus', 'message' => 'short'])
            ->assertRedirect(route('contact'))
            ->assertSessionHasErrors(['email', 'topic', 'message']);

        $this->get(route('contact'))
            ->assertSee('Please check the form')
            ->assertSee('aria-invalid="true"', false)
            ->assertSee('value="Ayesha"', false);

        Mail::assertNothingSent();
    }

    public function test_honeypot_blocks_bots(): void
    {
        $this->post(route('contact.store'), $this->validPayload(['website' => 'http://spam.example']))
            ->assertSessionHasErrors('website');

        Mail::assertNothingSent();
    }

    public function test_form_is_disabled_and_nothing_is_sent_without_a_recipient(): void
    {
        config(['competition.contact.recipient' => null]);

        $this->get(route('contact'))
            ->assertSee('Contact form unavailable')
            ->assertSee('disabled', false);

        $this->from(route('contact'))
            ->post(route('contact.store'), $this->validPayload())
            ->assertSessionHas('error');

        Mail::assertNothingSent();
    }

    public function test_submissions_are_rate_limited(): void
    {
        foreach (range(1, 5) as $i) {
            $this->post(route('contact.store'), $this->validPayload())->assertRedirect();
        }

        $this->post(route('contact.store'), $this->validPayload())->assertStatus(429)->assertSee('Too many requests');
    }

    public function test_form_includes_csrf_token(): void
    {
        // Laravel's test kernel skips CSRF verification, so assert the token is rendered for real browsers.
        $this->get(route('contact'))->assertSee('name="_token"', false);
    }
}
