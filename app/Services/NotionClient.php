<?php

namespace App\Services;

use App\Exceptions\ExternalApiException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class NotionClient
{
    /** @var array<int, string>|null */
    private ?array $candidatePropertyIds = null;

    public function queryDataSource(?string $cursor = null, ?string $editedAfter = null, ?string $date = null): array
    {
        $body = [
            'page_size' => 100,
            'sorts' => [['timestamp' => 'last_edited_time', 'direction' => 'ascending']],
        ];

        if ($cursor !== null) {
            $body['start_cursor'] = $cursor;
        }

        $filters = [];
        if ($editedAfter !== null) {
            $filters[] = [
                'timestamp' => 'last_edited_time',
                'last_edited_time' => ['on_or_after' => $editedAfter],
            ];
        }
        if ($date !== null) {
            $filters[] = [
                'property' => config('lucky.notion.property_date'),
                'date' => ['equals' => $date],
            ];
        }
        if (count($filters) === 1) {
            $body['filter'] = $filters[0];
        } elseif ($filters !== []) {
            $body['filter'] = ['and' => $filters];
        }

        $path = '/data_sources/'.config('lucky.notion.data_source_id').'/query';
        $propertyIds = $this->candidatePropertyIds();
        if ($propertyIds !== []) {
            $path .= '?'.implode('&', array_map(
                fn (string $id): string => 'filter_properties='.rawurlencode($id),
                $propertyIds,
            ));
        }

        return $this->request('post', $path, $body)->json();
    }

    public function getPage(string $pageId): array
    {
        return $this->request('get', '/pages/'.$pageId)->json();
    }

    /** @return array<int, array<string, mixed>> */
    public function findPagesByDate(string $date): array
    {
        $matches = [];
        $cursor = null;

        do {
            $page = $this->queryDataSource($cursor, date: $date);
            foreach ($page['results'] ?? [] as $result) {
                if ($this->parseCandidate($result)['target_date'] === $date) {
                    $matches[] = $result;
                }
            }
            $cursor = $page['next_cursor'] ?? null;
        } while (($page['has_more'] ?? false) === true && is_string($cursor));

        return $matches;
    }

    public function getPageBody(string $pageId): string
    {
        return trim($this->getBlockText($pageId));
    }

    public function parseCandidate(array $page): array
    {
        $properties = $page['properties'] ?? [];
        $dateProperty = $properties[config('lucky.notion.property_date')] ?? [];
        $priceProperty = $properties[config('lucky.notion.property_price')] ?? [];
        $titleProperty = $properties[config('lucky.notion.property_title')] ?? [];

        $date = data_get($dateProperty, 'date.start');
        $price = $priceProperty['number'] ?? null;
        $title = $this->plainText($titleProperty['title'] ?? $titleProperty['rich_text'] ?? []);

        return [
            'page_id' => (string) ($page['id'] ?? ''),
            'target_date' => is_string($date) ? substr($date, 0, 10) : null,
            'price_vnd' => is_numeric($price) ? (int) $price : null,
            'title' => trim($title),
            'last_edited_time' => (string) ($page['last_edited_time'] ?? ''),
        ];
    }

    public function updateResult(string $pageId, int $priceVnd, ?string $fileUploadId = null, ?string $fileName = null): void
    {
        $properties = [
            config('lucky.notion.property_price') => ['number' => $priceVnd],
        ];

        if ($fileUploadId !== null && $fileName !== null) {
            $properties[config('lucky.notion.property_wallpaper')] = [
                'files' => [[
                    'type' => 'file_upload',
                    'name' => $fileName,
                    'file_upload' => ['id' => $fileUploadId],
                ]],
            ];
        }

        $this->request('patch', '/pages/'.$pageId, ['properties' => $properties]);
    }

    public function createWallpaperPage(array $wallpaper): string
    {
        $response = $this->request('post', '/pages', [
            'parent' => [
                'type' => 'data_source_id',
                'data_source_id' => config('lucky.notion.data_source_id'),
            ],
            'properties' => [
                config('lucky.notion.property_date') => [
                    'date' => ['start' => $wallpaper['target_date']],
                ],
                config('lucky.notion.property_title') => [
                    'title' => [['type' => 'text', 'text' => ['content' => $wallpaper['title']]]],
                ],
                config('lucky.notion.property_price') => [
                    'number' => $wallpaper['price_vnd'],
                ],
            ],
            'children' => array_map(
                fn (string $text): array => [
                    'object' => 'block',
                    'type' => 'paragraph',
                    'paragraph' => [
                        'rich_text' => [[
                            'type' => 'text',
                            'text' => ['content' => $text],
                        ]],
                    ],
                ],
                mb_str_split((string) $wallpaper['body'], 1800),
            ),
        ]);

        return (string) $response->json('id');
    }

    public function uploadFile(string $bytes, string $fileName): string
    {
        $created = $this->request('post', '/file_uploads', [
            'mode' => 'single_part',
            'filename' => $fileName,
            'content_type' => 'image/jpeg',
        ]);
        $uploadId = (string) $created->json('id');

        if ($uploadId === '') {
            throw new ExternalApiException('notion_invalid_upload', false);
        }

        $this->throttle();
        $response = $this->client()
            ->attach('file', $bytes, $fileName, ['Content-Type' => 'image/jpeg'])
            ->post('/file_uploads/'.$uploadId.'/send');

        $this->ensureSuccess($response);

        return $uploadId;
    }

    public function wallpaperFileUrl(array $page): ?string
    {
        $files = data_get($page, 'properties.'.config('lucky.notion.property_wallpaper').'.files', []);
        $file = is_array($files) ? ($files[0] ?? null) : null;
        if (! is_array($file)) {
            return null;
        }

        return ($file['type'] ?? null) === 'file' ? data_get($file, 'file.url') : null;
    }

    private function getBlockText(string $blockId): string
    {
        $cursor = null;
        $lines = [];

        do {
            $query = ['page_size' => 100];
            if ($cursor !== null) {
                $query['start_cursor'] = $cursor;
            }

            $response = $this->request('get', '/blocks/'.$blockId.'/children', $query);
            foreach ($response->json('results', []) as $block) {
                $type = $block['type'] ?? null;
                if (is_string($type)) {
                    $text = $this->plainText(data_get($block, $type.'.rich_text', []));
                    if ($text !== '') {
                        $lines[] = $text;
                    }
                }
                if (($block['has_children'] ?? false) === true && isset($block['id'])) {
                    $childText = $this->getBlockText((string) $block['id']);
                    if ($childText !== '') {
                        $lines[] = $childText;
                    }
                }
            }
            $cursor = $response->json('next_cursor');
        } while ($response->json('has_more') === true && is_string($cursor));

        return implode("\n", $lines);
    }

    private function plainText(array $richText): string
    {
        return implode('', array_map(
            fn (array $item): string => (string) ($item['plain_text'] ?? data_get($item, 'text.content', '')),
            $richText,
        ));
    }

    private function request(string $method, string $path, array $data = []): Response
    {
        for ($attempt = 0; $attempt < 6; $attempt++) {
            $this->throttle();
            $response = $method === 'get'
                ? $this->client()->get($path, $data)
                : $this->client()->{$method}($path, $data);

            if ($response->successful()) {
                return $response;
            }

            if ($response->status() === 429 || $response->serverError()) {
                if ($attempt === 5) {
                    break;
                }
                $retryAfter = max(1, (int) $response->header('Retry-After'));
                sleep($response->status() === 429 ? $retryAfter : min(2 ** $attempt, 16));

                continue;
            }

            $this->ensureSuccess($response);
        }

        throw new ExternalApiException('notion_temporarily_unavailable', true);
    }

    private function client(): PendingRequest
    {
        $token = (string) config('lucky.notion.token');
        if ($token === '') {
            throw new ExternalApiException('notion_not_configured', false);
        }

        return Http::baseUrl('https://api.notion.com/v1')
            ->withToken($token)
            ->withHeaders(['Notion-Version' => config('lucky.notion.version')])
            ->acceptJson()
            ->timeout(60);
    }

    /** @return array<int, string> */
    private function candidatePropertyIds(): array
    {
        if ($this->candidatePropertyIds !== null) {
            return $this->candidatePropertyIds;
        }

        $schema = $this->request('get', '/data_sources/'.config('lucky.notion.data_source_id'))->json('properties', []);
        $wanted = [
            config('lucky.notion.property_date'),
            config('lucky.notion.property_price'),
            config('lucky.notion.property_title'),
        ];
        $ids = [];
        foreach ($wanted as $name) {
            $id = data_get($schema, $name.'.id');
            if (is_string($id) && $id !== '') {
                $ids[] = $id;
            }
        }

        return $this->candidatePropertyIds = $ids;
    }

    private function ensureSuccess(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        throw new ExternalApiException(
            'notion_http_'.$response->status(),
            $response->status() === 429 || $response->serverError(),
        );
    }

    private function throttle(): void
    {
        if (! app()->environment('testing')) {
            usleep(500_000);
        }
    }
}
