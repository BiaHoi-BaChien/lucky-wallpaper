<?php

namespace App\Services;

use App\Exceptions\ExternalApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class OpenAiClient
{
    public function isConfigured(): bool
    {
        return trim((string) config('lucky.openai.api_key')) !== '';
    }

    public function composition(string $prompt): string
    {
        $schema = KigakuPolicy::schema();
        // Structured Outputs does not support uniqueItems; parseProposal enforces uniqueness.
        unset($schema['properties']['kigaku']['properties']['candidate_directions']['uniqueItems']);
        $response = $this->post('responses', [
            'model' => config('lucky.openai.text_model'),
            'input' => $prompt,
            'store' => false,
            'max_output_tokens' => 8000,
            'text' => ['format' => [
                'type' => 'json_schema', 'name' => 'wallpaper_composition', 'strict' => true, 'schema' => $schema,
            ]],
        ], 120, 2_000_000);
        if (($response['status'] ?? null) !== 'completed') {
            throw new ExternalApiException('openai_incomplete_response');
        }
        if (! is_array($response['output'] ?? null)) {
            throw new ExternalApiException('openai_invalid_response');
        }
        $text = '';
        foreach ($response['output'] as $output) {
            if (! is_array($output) || ! is_array($output['content'] ?? null)) {
                continue;
            }
            foreach ($output['content'] as $content) {
                if (! is_array($content)) {
                    continue;
                }
                if (($content['type'] ?? null) === 'refusal') {
                    throw new ExternalApiException('openai_refused');
                }
                if (($content['type'] ?? null) === 'output_text' && is_string($content['text'] ?? null)) {
                    $text .= $content['text'];
                }
            }
        }
        if ($text === '') {
            throw new ExternalApiException('openai_invalid_response');
        }

        return $text;
    }

    public function image(string $prompt): string
    {
        $response = $this->post('images/generations', [
            'model' => config('lucky.openai.image_model'),
            'prompt' => $prompt,
            'n' => 1,
            'size' => '1440x2560',
            'quality' => 'high',
            'output_format' => 'jpeg',
        ], 240, 28_000_000);
        $encoded = $response['data'][0]['b64_json'] ?? null;
        $bytes = is_string($encoded) ? base64_decode($encoded, true) : false;
        if ($bytes === false || $bytes === '' || strlen($bytes) > 20_000_000) {
            throw new ExternalApiException('openai_invalid_image');
        }

        return $bytes;
    }

    private function post(string $endpoint, array $payload, int $timeout, int $maxBytes): array
    {
        if (! $this->isConfigured()) {
            throw new ExternalApiException('openai_not_configured');
        }
        try {
            // Paid generation requests are never retried automatically.
            $response = Http::withToken(trim((string) config('lucky.openai.api_key')))->acceptJson()
                ->connectTimeout(10)->timeout($timeout)->withOptions(['stream' => true, 'read_timeout' => $timeout])
                ->post('https://api.openai.com/v1/'.$endpoint, $payload);
        } catch (ConnectionException) {
            throw new ExternalApiException('openai_connection_failed');
        }
        if (! $response->successful()) {
            throw new ExternalApiException(match ($response->status()) {
                401, 403 => 'openai_auth_failed',
                429 => 'openai_rate_limited',
                default => 'openai_request_failed',
            });
        }
        $stream = $response->toPsrResponse()->getBody();
        $body = '';
        while (! $stream->eof()) {
            $chunk = $stream->read(min(8192, $maxBytes - strlen($body) + 1));
            $body .= $chunk;
            if (strlen($body) > $maxBytes) {
                throw new ExternalApiException('openai_response_too_large');
            }
            if ($chunk === '') {
                break;
            }
        }
        $decoded = json_decode($body, true);
        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new ExternalApiException('openai_invalid_response');
        }

        return $decoded;
    }
}
