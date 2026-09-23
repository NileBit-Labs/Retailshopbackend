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
            $data = $response->json();

            // A normal response either has a candidate or explicit prompt feedback. Do not turn
            // an invalid 2xx body into a misleading successful Ask reply.
            $hasCandidate = is_array($data)
                && isset($data['candidates'][0]['content']['parts'])
                && is_array($data['candidates'][0]['content']['parts']);
            $hasPromptFeedback = is_array($data) && isset($data['promptFeedback']) && is_array($data['promptFeedback']);

            if (! $hasCandidate && ! $hasPromptFeedback) {
                Log::warning('ask.gemini_malformed_response', [
                    'status' => $response->status(),
                    'body' => str_replace($key, '[redacted]', Str::limit($response->body(), 600)),
                ]);

                throw new AskException('malformed_response', 502, 'The AI service sent an invalid response. Try again in a moment.');
            }

            return $data;
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

        if ($status === 404 && (str_contains($text, 'model') || str_contains($text, 'not_found'))) {
            throw new AskException('model_unavailable', 503, 'The configured AI model is unavailable. The owner should check GEMINI_MODEL in the server settings.');
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
