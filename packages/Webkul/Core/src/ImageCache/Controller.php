<?php

namespace Webkul\Core\ImageCache;

use Closure;
use Illuminate\Http\Response as IlluminateResponse;
use Illuminate\Support\Facades\Cache;
use Intervention\Image\ImageManager;

class Controller
{
    /**
     * Logo
     *
     * @var string
     */
    const PIM_LOGO = 'https://updates.unopim.com/unopim.png';

    /**
     * Cache template
     *
     * @var string
     */
    protected $template;

    /**
     * Get HTTP response of either original image file or
     * template applied file.
     *
     * @param  string  $template
     * @param  string  $filename
     * @return IlluminateResponse
     */
    public function getResponse($template, $filename)
    {
        switch (strtolower($template)) {
            case 'original':
                return $this->getOriginal($filename);

            case 'download':
                return $this->getDownload($filename);

            default:
                return $this->getImage($template, $filename);
        }
    }

    /**
     * Get HTTP response of template applied image file
     *
     * @param  string  $template
     * @param  string  $filename
     * @return IlluminateResponse
     */
    public function getImage($template, $filename)
    {
        $this->template = $template;
        $cacheTime = $template === 'logo' ? 10080 : config('imagecache.lifetime');
        $cacheKey = "imagecache:{$template}:{$filename}";

        $manager = app(ImageManager::class);

        if ($template === 'logo') {
            $path = self::PIM_LOGO;
            $templateCallback = null;
        } else {
            $path = $this->getImagePath($filename);
            $templateCallback = $this->getTemplate($template);
        }

        try {
            $content = Cache::remember($cacheKey, $cacheTime * 60, function () use ($manager, $path, $templateCallback) {
                $img = $manager->read($path);

                if ($templateCallback instanceof Closure) {
                    $templateCallback($img);
                } elseif (is_object($templateCallback) && method_exists($templateCallback, 'apply')) {
                    $templateCallback->apply($img);
                }

                // Return image binary data
                return (string) $img->encode();
            });
        } catch (\Exception $e) {
            if ($template !== 'logo') {
                abort(404);
            }
            $content = '';
        }

        return $this->buildResponse($content);
    }

    /**
     * Builds HTTP response from given image data
     *
     * @param  string  $content
     * @return IlluminateResponse
     */
    protected function buildResponse($content)
    {
        // Define mime type
        $mime = finfo_buffer(finfo_open(FILEINFO_MIME_TYPE), $content);

        // Respond with 304 not modified if browser has the image cached
        $eTag = md5($content);
        $notModified = isset($_SERVER['HTTP_IF_NONE_MATCH']) && $_SERVER['HTTP_IF_NONE_MATCH'] === $eTag;
        $content = $notModified ? null : $content;

        $statusCode = $notModified ? 304 : 200;
        $maxAge = ($this->template === 'logo' ? 10080 : config('imagecache.lifetime')) * 60;

        // Return HTTP response
        return new IlluminateResponse($content, $statusCode, [
            'Content-Type'   => $mime,
            'Cache-Control'  => 'max-age='.$maxAge.', public',
            'Content-Length' => $content ? strlen($content) : 0,
            'Etag'           => $eTag,
        ]);
    }

    /**
     * Placeholder for getting original image
     */
    protected function getOriginal($filename)
    {
        $path = $this->getImagePath($filename);
        abort_if(! file_exists($path), 404);
        $content = file_get_contents($path);

        return $this->buildResponse($content);
    }

    /**
     * Placeholder for download response
     */
    protected function getDownload($filename)
    {
        $path = $this->getImagePath($filename);
        if (! file_exists($path)) {
            abort(404);
        }

        return response()->download($path);
    }

    /**
     * Placeholder for fetching image path
     */
    protected function getImagePath($filename)
    {
        // Replace with actual image path logic
        return storage_path("app/public/{$filename}");
    }

    /**
     * Placeholder for fetching template
     */
    protected function getTemplate($template)
    {
        // Return either a Closure or a filter object
        return null;
    }
}
