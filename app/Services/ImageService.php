<?php

namespace App\Services;

use App\Exceptions\ExternalApiException;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImageService
{
    public function transcodeToJpeg(string $sourceBytes): string
    {
        $image = $this->decodeSourceImage($sourceBytes, 'invalid_external_image');

        ob_start();
        imagejpeg($image, null, (int) config('lucky.image.jpeg_quality'));
        $bytes = ob_get_clean();
        imagedestroy($image);

        if (! is_string($bytes) || $bytes === '') {
            throw new ExternalApiException('image_encoding_failed', false);
        }

        return $bytes;
    }

    public function normalizeAndStore(string $sourceBytes): array
    {
        $image = $this->decodeSourceImage($sourceBytes, 'invalid_generated_image');

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

    public function readLimitedResponse(ClientResponse $response): string
    {
        $maxBytes = max(1, (int) config('lucky.notion.max_download_bytes'));
        $contentLength = trim((string) $response->header('Content-Length'));
        if ($contentLength !== '' && ctype_digit($contentLength) && (int) $contentLength > $maxBytes) {
            throw new ExternalApiException('notion_image_too_large', false);
        }

        $stream = $response->toPsrResponse()->getBody();
        $bytes = '';

        while (! $stream->eof()) {
            $remaining = $maxBytes - strlen($bytes);
            if ($remaining < 0) {
                throw new ExternalApiException('notion_image_too_large', false);
            }
            $chunk = $stream->read(min(8192, $remaining + 1));
            if ($chunk === '') {
                break;
            }
            $bytes .= $chunk;
        }

        if (strlen($bytes) > $maxBytes) {
            throw new ExternalApiException('notion_image_too_large', false);
        }

        return $bytes;
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

    private function decodeSourceImage(string $sourceBytes, string $errorCode): \GdImage
    {
        $size = @getimagesizefromstring($sourceBytes);
        if (! is_array($size)) {
            throw new ExternalApiException($errorCode, false);
        }

        $sourceWidth = $size[0];
        $sourceHeight = $size[1];
        $maxWidth = max(1, (int) config('lucky.image.max_source_width'));
        $maxHeight = max(1, (int) config('lucky.image.max_source_height'));
        $maxPixels = max(1, (int) config('lucky.image.max_source_pixels'));
        if (
            $sourceWidth < 1
            || $sourceHeight < 1
            || $sourceWidth > $maxWidth
            || $sourceHeight > $maxHeight
            || $sourceWidth > intdiv($maxPixels, $sourceHeight)
        ) {
            throw new ExternalApiException($errorCode, false);
        }

        $image = @imagecreatefromstring($sourceBytes);
        if ($image === false) {
            throw new ExternalApiException($errorCode, false);
        }

        return $image;
    }
}
