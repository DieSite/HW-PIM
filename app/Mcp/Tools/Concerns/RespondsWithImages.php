<?php

namespace App\Mcp\Tools\Concerns;

use Laravel\Mcp\Response;

trait RespondsWithImages
{
    /**
     * The JSON result followed by each labelled JPEG preview, so the client
     * can look at the photos it just changed.
     *
     * @param  array<string, mixed>  $result
     * @param  list<array{label: string, image: string}>  $previews
     * @return list<Response>
     */
    protected function withImages(array $result, array $previews): array
    {
        $responses = [Response::json($result)];

        foreach ($previews as $preview) {
            $responses[] = Response::text("Preview: {$preview['label']}");
            $responses[] = Response::image($preview['image'], 'image/jpeg');
        }

        return $responses;
    }
}
