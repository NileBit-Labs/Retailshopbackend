<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AskCheckCommandTest extends TestCase
{
    private function reply(array $parts): array
    {
        return ['candidates' => [['content' => ['role' => 'model', 'parts' => $parts]]]];
    }

    public function test_it_reports_a_missing_key_without_calling_anyone(): void
    {
        config(['services.gemini.key' => null]);
        Http::fake();

        $this->artisan('ask:check')->expectsOutputToContain('No GEMINI_API_KEY')->assertFailed();

        Http::assertNothingSent();
    }

    public function test_it_passes_when_the_tool_round_trip_works_and_never_prints_the_key(): void
    {
        config(['services.gemini.key' => 'super-secret-key']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()
            ->push($this->reply([['text' => 'OK']]))
            ->push($this->reply([['functionCall' => ['id' => 'check-call', 'name' => 'get_lucky_number', 'args' => ['shop' => 'x']]]]))
            ->push($this->reply([['text' => 'The lucky number is 4217.']])),
        ]);

        $this->artisan('ask:check')->expectsOutputToContain('Tool calling works end to end')->doesntExpectOutputToContain('super-secret-key')->assertSuccessful();
    }

    public function test_it_says_what_is_wrong_when_google_refuses_the_key(): void
    {
        config(['services.gemini.key' => 'bad-key']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => ['message' => 'API key not valid', 'details' => [['reason' => 'API_KEY_INVALID']]]], 400)]);

        $this->artisan('ask:check')->expectsOutputToContain('invalid_key')->doesntExpectOutputToContain('bad-key')->assertFailed();
    }

    public function test_it_fails_when_the_model_ignores_the_tool(): void
    {
        config(['services.gemini.key' => 'k']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()
            ->push($this->reply([['text' => 'OK']]))
            ->push($this->reply([['text' => 'I do not know.']])),
        ]);

        $this->artisan('ask:check')->expectsOutputToContain('without using the tool')->assertFailed();
    }

    public function test_it_fails_when_a_tool_call_has_no_id(): void
    {
        config(['services.gemini.key' => 'k']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()
            ->push($this->reply([['text' => 'OK']]))
            ->push($this->reply([['functionCall' => ['name' => 'get_lucky_number', 'args' => []]]])),
        ]);

        $this->artisan('ask:check')->expectsOutputToContain('without an ID')->assertFailed();
    }
}
