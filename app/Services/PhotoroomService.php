<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class PhotoroomService
{
    /**
     * Remove text/logo from an image using Photoroom's AI text removal API.
     *
     * @param  string  $imageContent  Raw image binary content
     * @param  string  $filename  Original filename (used for mime detection)
     * @return string Raw PNG image binary content with text removed
     */
    public function removeText(string $imageContent, string $filename): string
    {
        /** @var Response $response */
        $response = Http::withHeader('x-api-key', config('photoroom.api_key'))
            ->attach('imageFile', $imageContent, $filename)
            ->post(config('photoroom.api_url'), [
                'removeBackground' => 'false',
                'referenceBox' => 'originalImage',
                'editWithAI.mode' => 'ai.auto',
                'editWithAI.prompt' => 'Remove the icon on the bottom left. Don\'t touch anything else.'
            ]);

        if (! $response->successful()) {
            throw new RuntimeException(
                "Photoroom API error [{$response->status()}]: {$response->body()}"
            );
        }

        return $response->body();
    }
}
