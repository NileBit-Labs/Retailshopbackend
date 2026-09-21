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

    /** The model that answered the last request, for the check command and the logs. */
    public ?string $lastModel = null;

    /** @return array<int, string> the main model first, then the fallbacks */
    public function models(): array
    {
        return array_values(array_unique(array_filter([config('services.gemini.model'), ...(array) config('services.gemini.fallback_models', [])])));
    }

    /**
     * Google's models are sometimes overloaded ("high demand") or retired, so a busy or missing
     * model is retried once and then the next model is tried. A refused key or a malformed request
     * would fail on every model, so those stop at once.
     *
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

        $last = null;
        $began = microtime(true);

        foreach ($this->models() as $position => $model) {
            // Google having a bad minute shouldn't keep a person waiting through every model.
            if ($last !== null && microtime(true) - $began > (int) config('services.gemini.budget_seconds', 45)) {
                break;
            }

            try {
                $response = $this->post($model, $payload, $key, $position === 0 ? 2 : 1);
            } catch (ConnectionException) {
                $last = new AskException('unavailable', 503, "Couldn't reach the AI service. Check the internet connection and try again.");

                continue;
            }

            if ($response->successful()) {
                $this->lastModel = $model;

                return $response->json() ?? [];
            }

            $error = $this->classify($response, $key, $model);

            if (in_array($error->errorCode, ['invalid_key', 'bad_request'], true)) {
                throw $error;
            }

            $last = $error;
        }

        throw $last ?? new AskException('unavailable', 503, 'The AI service is having trouble right now. Try again in a moment.');
    }

    /** One request, tried again after a short pause if Google says it is overloaded. */
    private function post(string $model, array $payload, string $key, int $tries): Response
    {
        $url = rtrim(config('services.gemini.base_url'), '/').'/models/'.$model.':generateContent';

        for ($attempt = 1; ; $attempt++) {
            $response = Http::withHeaders(['x-goog-api-key' => $key])
                ->acceptJson()
                ->connectTimeout(10)
                ->timeout((int) config('services.gemini.timeout'))
                ->post($url, $payload);

            if ($attempt >= $tries || ! in_array($response->status(), [500, 502, 503, 504], true)) {
                return $response;
            }

            usleep(max(0, (int) config('services.gemini.retry_pause_ms', 900)) * 1000);
        }
    }

    private function classify(Response $response, string $key, string $model): AskException
    {
        Log::warning('ask.gemini_error', [
            'model' => $model,
            'status' => $response->status(),
            'body' => str_replace($key, '[redacted]', Str::limit($response->body(), 600)),
        ]);

        $status = $response->status();
        $text = strtolower($response->body());

        if ($status === 429) {
            return new AskException('busy', 429, "The AI service is busy or today's allowance has run out. Try again in a minute.");
        }

        if ($status === 404) {
            return new AskException('model_unavailable', 502, "The AI model isn't available. The owner should set GEMINI_MODEL to a current model name.");
        }

        if (in_array($status, [401, 403], true) || ($status === 400 && (str_contains($text, 'api_key_invalid') || str_contains($text, 'api key not valid')))) {
            return new AskException('invalid_key', 502, 'The AI key was refused. The owner should check the GEMINI_API_KEY in the server settings.');
        }

        if ($status >= 500) {
            return new AskException('unavailable', 503, 'The AI service is having trouble right now. Try again in a moment.');
        }

        return new AskException('bad_request', 502, "The AI service couldn't handle that question. Try asking it another way.");
    }
}
