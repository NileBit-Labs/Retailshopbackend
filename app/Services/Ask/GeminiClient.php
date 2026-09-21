<?php

namespace App\Services\Ask;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The one place that talks to Google. The key is sent in a header (never in a URL that could be
 * logged) and is scrubbed from anything written to the logs.
 */
class GeminiClient
{
    public function enabled(): bool
    {
        return filled(config('services.gemini.key'));
    }

    /**
     * @param  array<string, mixed>  $payload  a generateContent request body
     * @return array<string, mixed>
     *
     * @throws AskException
     */
    public function generate(array $payload): array
    {
        $key = config('services.gemini.key');

        if (blank($key)) {
            throw new AskException('not_configured', 503, "The AI assistant isn't set up yet. The owner needs to add a GEMINI_API_KEY to the server's settings.");
        }

        $url = rtrim(config('services.gemini.base_url'), '/').'/models/'.config('services.gemini.model').':generateContent';

        try {
            $response = Http::withHeaders(['x-goog-api-key' => $key])
                ->acceptJson()
                ->connectTimeout(10)
                ->timeout((int) config('services.gemini.timeout'))
                ->post($url, $payload);
        } catch (ConnectionException) {
            throw new AskException('unavailable', 503, "Couldn't reach the AI service. Check the internet connection and try again.");
        }

        if ($response->successful()) {
            return $response->json() ?? [];
        }

        $this->fail($response, $key);
    }

    private function fail(Response $response, string $key): never
    {
        Log::warning('ask.gemini_error', [
            'status' => $response->status(),
            'body' => str_replace($key, '[redacted]', Str::limit($response->body(), 600)),
        ]);

        $status = $response->status();
        $text = strtolower($response->body());

        if ($status === 429) {
            throw new AskException('busy', 429, "The AI service is busy or today's allowance has run out. Try again in a minute.");
        }

        if (in_array($status, [400, 401, 403], true) && (str_contains($text, 'api_key_invalid') || str_contains($text, 'api key not valid') || str_contains($text, 'permission_denied') || $status !== 400)) {
            throw new AskException('invalid_key', 502, 'The AI key was refused. The owner should check the GEMINI_API_KEY in the server settings.');
        }

        if ($status >= 500) {
            throw new AskException('unavailable', 503, 'The AI service is having trouble right now. Try again in a moment.');
        }

        throw new AskException('bad_request', 502, "The AI service couldn't handle that question. Try asking it another way.");
    }
}
