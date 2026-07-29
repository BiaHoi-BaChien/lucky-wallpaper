<?php

return [
    'timezone' => env('LUCKY_TIMEZONE', 'Asia/Ho_Chi_Minh'),
    'setup_key' => env('APP_SETUP_KEY'),
    'notion' => [
        'token' => env('NOTION_TOKEN'),
        'data_source_id' => env('NOTION_DATA_SOURCE_ID', '3a584dd4-00e3-807d-8e33-000b9c6561cb'),
        'version' => env('NOTION_VERSION', '2026-03-11'),
        'property_date' => 'Date',
        'property_price' => 'Price',
        'property_title' => 'title',
        'property_wallpaper' => 'WallPaper',
        'max_upload_bytes' => 4_500_000,
    ],
    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'text_model' => env('OPENAI_TEXT_MODEL', 'gpt-5.6-terra'),
        'image_model' => env('OPENAI_IMAGE_MODEL', 'gpt-image-2'),
        'reasoning_effort' => env('OPENAI_REASONING_EFFORT', 'medium'),
        'prompt_version' => env('OPENAI_PROMPT_VERSION', 'v1'),
        'timeout' => (int) env('OPENAI_TIMEOUT', 180),
    ],
    'analysis' => [
        'records_per_chunk' => 100,
        'characters_per_chunk' => 120_000,
    ],
    'image' => [
        'width' => 1440,
        'height' => 2560,
        'quality' => 'high',
        'jpeg_quality' => 85,
        'disk' => env('WALLPAPER_DISK', 'local'),
        'directory' => 'wallpapers',
    ],
];
