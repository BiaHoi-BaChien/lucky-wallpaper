<?php

namespace App\Services;

use App\Exceptions\ExternalApiException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImageService
{
    public function normalizeAndStore(string $sourceBytes): array
    {
        $image = @imagecreatefromstring($sourceBytes);
        if ($image === false) {
            throw new ExternalApiException('invalid_generated_image', false);
        }

        $width = (int) config('lucky.image.width');
        $height = (int) config('lucky.image.height');
        $target = imagecreatetruecolor($width, $height);
        imagecopyresampled(
            $target,
            $image,
            0,
            0,
            0,
            0,
            $width,
            $height,
            imagesx($image),
            imagesy($image),
        );

        ob_start();
        imagejpeg($target, null, (int) config('lucky.image.jpeg_quality'));
        $bytes = ob_get_clean();
        imagedestroy($image);
        imagedestroy($target);

        if (! is_string($bytes) || $bytes === '') {
            throw new ExternalApiException('image_encoding_failed', false);
        }

        $path = trim((string) config('lucky.image.directory'), '/').'/'.Str::uuid().'.jpg';
        $disk = (string) config('lucky.image.disk');
        Storage::disk($disk)->put($path, $bytes);

        return [
            'disk' => $disk,
            'path' => $path,
            'mime' => 'image/jpeg',
            'bytes' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
        ];
    }

    public function fitForNotion(string $bytes): string
    {
        $maxBytes = (int) config('lucky.notion.max_upload_bytes');
        if (strlen($bytes) <= $maxBytes) {
            return $bytes;
        }

        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            throw new ExternalApiException('invalid_local_image', false);
        }

        foreach ([78, 70, 62, 54, 46] as $quality) {
            ob_start();
            imagejpeg($image, null, $quality);
            $compressed = ob_get_clean();
            if (is_string($compressed) && strlen($compressed) <= $maxBytes) {
                imagedestroy($image);

                return $compressed;
            }
        }
        imagedestroy($image);

        throw new ExternalApiException('notion_file_too_large', false);
    }
}
