<?php

namespace App\Services;

use App\Exceptions\ExternalApiException;
use App\Models\ApiRun;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use JsonException;

class OpenAiClient
{
    public const PROPOSAL_SCHEMA = [
        'type' => 'object',
        'additionalProperties' => false,
        'required' => ['title', 'art_style', 'conclusion', 'overview', 'composition', 'color_wu_xing', 'symbolism'],
        'properties' => [
            'title' => ['type' => 'string'],
            'art_style' => ['type' => 'string'],
            'conclusion' => ['type' => 'string'],
            'overview' => ['type' => 'string'],
            'composition' => ['type' => 'string'],
            'color_wu_xing' => ['type' => 'string'],
            'symbolism' => ['type' => 'string'],
        ],
    ];

    public function structured(ApiRun $run, string $instructions, string $input, array $schema, string $schemaName): array
    {
        $run->update(['status' => 'running', 'started_at' => now()]);

        try {
            $response = $this->postWithRetry('/responses', [
                'model' => $run->model,
                'reasoning' => ['effort' => config('lucky.openai.reasoning_effort')],
                'instructions' => $instructions,
                'input' => $input,
                'text' => [
                    'format' => [
                        'type' => 'json_schema',
                        'name' => $schemaName,
                        'strict' => true,
                        'schema' => $schema,
                    ],
                ],
            ]);

            $text = $this->extractOutputText($response->json());
            $decoded = json_decode($text, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($decoded)) {
                throw new ExternalApiException('openai_invalid_output', false);
            }

            $this->completeRun($run, $response);

            return $decoded;
        } catch (JsonException) {
            $run->update([
                'status' => 'failed',
                'error_code' => 'openai_invalid_json',
                'retryable' => false,
                'finished_at' => now(),
            ]);
            throw new ExternalApiException('openai_invalid_json', false);
        } catch (ExternalApiException $exception) {
            $run->update([
                'status' => 'failed',
                'error_code' => $exception->errorCode,
                'retryable' => $exception->retryable,
                'finished_at' => now(),
            ]);
            throw $exception;
        }
    }

    public function image(ApiRun $run, string $prompt): string
    {
        $run->update(['status' => 'running', 'started_at' => now()]);

        try {
            $response = $this->postWithRetry('/images/generations', [
                'model' => $run->model,
                'prompt' => $prompt,
                'size' => config('lucky.image.width').'x'.config('lucky.image.height'),
                'quality' => config('lucky.image.quality'),
                'output_format' => 'jpeg',
                'output_compression' => config('lucky.image.jpeg_quality'),
                'n' => 1,
            ]);
            $encoded = $response->json('data.0.b64_json');
            $bytes = is_string($encoded) ? base64_decode($encoded, true) : false;
            if (! is_string($bytes) || $bytes === '') {
                throw new ExternalApiException('openai_invalid_image', false);
            }

            $this->completeRun($run, $response);

            return $bytes;
        } catch (ExternalApiException $exception) {
            $run->update([
                'status' => 'failed',
                'error_code' => $exception->errorCode,
                'retryable' => $exception->retryable,
                'finished_at' => now(),
            ]);
            throw $exception;
        }
    }

    private function postWithRetry(string $path, array $payload): Response
    {
        $apiKey = (string) config('lucky.openai.api_key');
        if ($apiKey === '') {
            throw new ExternalApiException('openai_not_configured', false);
        }

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $response = Http::baseUrl('https://api.openai.com/v1')
                ->withToken($apiKey)
                ->acceptJson()
                ->timeout((int) config('lucky.openai.timeout'))
                ->post($path, $payload);

            if ($response->successful()) {
                return $response;
            }

            if (($response->status() === 429 || $response->serverError()) && $attempt < 4) {
                $retryAfter = max(1, (int) $response->header('Retry-After'));
                sleep($response->status() === 429 ? $retryAfter : min(2 ** $attempt, 16));

                continue;
            }

            $error = $response->json('error.code');
            throw new ExternalApiException(
                is_string($error) && $error !== '' ? 'openai_'.$error : 'openai_http_'.$response->status(),
                $response->status() === 429 || $response->serverError(),
            );
        }

        throw new ExternalApiException('openai_temporarily_unavailable', true);
    }

    private function extractOutputText(array $payload): string
    {
        foreach ($payload['output'] ?? [] as $output) {
            foreach ($output['content'] ?? [] as $content) {
                if (($content['type'] ?? null) === 'refusal') {
                    throw new ExternalApiException('openai_refusal', false);
                }
                if (($content['type'] ?? null) === 'output_text' && is_string($content['text'] ?? null)) {
                    return $content['text'];
                }
            }
        }

        throw new ExternalApiException('openai_empty_output', false);
    }

    private function completeRun(ApiRun $run, Response $response): void
    {
        $run->update([
            'status' => 'succeeded',
            'openai_request_id' => $response->header('x-request-id'),
            'input_tokens' => $response->json('usage.input_tokens'),
            'output_tokens' => $response->json('usage.output_tokens'),
            'finished_at' => now(),
        ]);
    }
}
