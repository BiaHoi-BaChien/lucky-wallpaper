<?php

namespace Tests\Unit;

use App\Services\OpenAiClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OpenAiClientTest extends TestCase
{
    #[DataProvider('generationRequests')]
    public function test_streaming_read_timeout_matches_the_generation_timeout(string $method, int $timeout): void
    {
        config(['lucky.openai.api_key' => 'test-key']);
        Http::preventStrayRequests();
        $expected = $method === 'composition' ? '{"title":"test"}' : 'image bytes';
        Http::fake(function (Request $request, array $options) use ($method, $timeout, $expected) {
            $this->assertSame('https://api.openai.com/v1/'.($method === 'composition' ? 'responses' : 'images/generations'), $request->url());
            $this->assertTrue($options['stream']);
            $this->assertSame(10, $options['connect_timeout']);
            $this->assertSame($timeout, $options['timeout']);
            $this->assertSame($timeout, $options['read_timeout'] ?? null);

            return Http::response($method === 'composition'
                ? ['status' => 'completed', 'output' => [['content' => [['type' => 'output_text', 'text' => $expected]]]]]
                : ['data' => [['b64_json' => base64_encode($expected)]]]);
        });

        $this->assertSame($expected, app(OpenAiClient::class)->{$method}('test prompt'));
        Http::assertSentCount(1);
    }

    public static function generationRequests(): array
    {
        return [
            'composition' => ['composition', 120],
            'image' => ['image', 240],
        ];
    }
}
