<?php

namespace App\Console\Commands;

use App\Services\Ask\AskException;
use App\Services\Ask\GeminiClient;
use Illuminate\Console\Command;

/**
 * Proves the AI settings work before anyone relies on them: the key is accepted, the model exists
 * and a tool-calling round trip (the thing "Ask Your Shop" depends on) comes back. It never prints
 * the key.
 */
class AskCheck extends Command
{
    protected $signature = 'ask:check';

    protected $description = 'Check the Gemini key and model used by Ask Your Shop';

    public function handle(GeminiClient $gemini): int
    {
        $model = config('services.gemini.model');
        $this->line("Model: {$model}");

        if (! $gemini->enabled()) {
            $this->error('No GEMINI_API_KEY is set. Add it to the backend .env file, then run this again.');

            return self::FAILURE;
        }

        try {
            $hello = $gemini->generate(['contents' => [['role' => 'user', 'parts' => [['text' => 'Reply with the single word: OK']]]]]);
            $said = $this->text($hello['candidates'][0]['content']['parts'] ?? []);
            $this->info('1/2  The key and model work. The AI said: '.($said === '' ? '(nothing)' : trim($said)));

            $tool = ['functionDeclarations' => [[
                'name' => 'get_lucky_number',
                'description' => 'Returns the shop\'s lucky number.',
                'parameters' => ['type' => 'object', 'properties' => ['shop' => ['type' => 'string', 'description' => 'Any text.']]],
            ]]];

            $contents = [['role' => 'user', 'parts' => [['text' => 'What is the shop\'s lucky number? Use the tool, then tell me the number.']]]];
            $first = $gemini->generate(['contents' => $contents, 'tools' => [$tool]]);
            $parts = $first['candidates'][0]['content']['parts'] ?? [];
            $call = collect($parts)->first(fn ($p) => isset($p['functionCall']));

            if (! $call) {
                $this->warn('2/2  The AI answered without using the tool. Tool calling may not be supported by this model; try another GEMINI_MODEL.');

                return self::FAILURE;
            }

            $callId = $call['functionCall']['id'] ?? null;
            if (! is_string($callId) || $callId === '') {
                $this->warn('2/2  The AI returned a tool call without an ID. The response is not compatible with this model.');

                return self::FAILURE;
            }

            $contents[] = ['role' => 'model', 'parts' => $parts];
            $answer = ['functionResponse' => [
                'id' => $callId,
                'name' => $call['functionCall']['name'],
                'response' => ['result' => ['lucky_number' => 4217]],
            ]];
            $contents[] = ['role' => 'user', 'parts' => [$answer]];
            $second = $gemini->generate(['contents' => $contents, 'tools' => [$tool]]);
            $final = $this->text($second['candidates'][0]['content']['parts'] ?? []);

            if (! str_contains($final, '4217')) {
                $this->warn("2/2  The tool was called but the final answer didn't use its result: ".trim($final));

                return self::FAILURE;
            }

            $this->info('2/2  Tool calling works end to end. Ask Your Shop is ready to use.');

            return self::SUCCESS;
        } catch (AskException $e) {
            $this->error("[{$e->errorCode}] {$e->getMessage()}");

            return self::FAILURE;
        }
    }

    /** @param  array<int, array<string, mixed>>  $parts */
    private function text(array $parts): string
    {
        return implode('', array_map(fn ($p) => (string) ($p['text'] ?? ''), array_filter($parts, fn ($p) => empty($p['thought']))));
    }
}
