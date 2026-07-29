<?php

namespace Tests\Feature;

use App\Exceptions\ExternalApiException;
use App\Models\ApiRun;
use App\Models\Wallpaper;
use App\Services\OpenAiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenAiClientTest extends TestCase
{
    use RefreshDatabase;

    public function test_structured_output_is_parsed_and_usage_is_recorded(): void
    {
        config(['lucky.openai.api_key' => 'test']);
        $run = $this->makeApiRun();
        $payload = [
            'title' => '聚宝盆の朝',
            'art_style' => '実写写真',
            'conclusion' => '聚宝盆の朝 × 実写写真',
            'overview' => '概要',
            'composition' => '配置',
            'color_wu_xing' => '金と水',
            'symbolism' => '象徴',
        ];
        Http::fake([
            'api.openai.com/v1/responses' => Http::response([
                'output' => [[
                    'content' => [['type' => 'output_text', 'text' => json_encode($payload, JSON_UNESCAPED_UNICODE)]],
                ]],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 20],
            ], 200, ['x-request-id' => 'req_test']),
        ]);

        $result = app(OpenAiClient::class)->structured(
            $run,
            'instructions',
            'input',
            OpenAiClient::PROPOSAL_SCHEMA,
            'wallpaper_composition',
        );

        $this->assertSame($payload, $result);
        $this->assertDatabaseHas('api_runs', [
            'id' => $run->id,
            'status' => 'succeeded',
            'openai_request_id' => 'req_test',
            'input_tokens' => 10,
            'output_tokens' => 20,
        ]);
    }

    public function test_refusal_is_a_non_retryable_failure(): void
    {
        config(['lucky.openai.api_key' => 'test']);
        $run = $this->makeApiRun();
        Http::fake([
            'api.openai.com/v1/responses' => Http::response([
                'output' => [['content' => [['type' => 'refusal', 'refusal' => 'no']]]],
            ]),
        ]);

        try {
            app(OpenAiClient::class)->structured($run, 'instructions', 'input', OpenAiClient::PROPOSAL_SCHEMA, 'schema');
            $this->fail('Expected exception was not thrown.');
        } catch (ExternalApiException $exception) {
            $this->assertSame('openai_refusal', $exception->errorCode);
            $this->assertFalse($exception->retryable);
        }
    }

    private function makeApiRun(): ApiRun
    {
        $wallpaper = Wallpaper::factory()->create();

        return ApiRun::query()->create([
            'type' => 'composition_proposal',
            'model' => 'gpt-5.6-terra',
            'prompt_version' => 'v1',
            'input_hash' => str_repeat('a', 64),
            'subject_type' => $wallpaper->getMorphClass(),
            'subject_id' => $wallpaper->id,
        ]);
    }
}
