<?php

namespace Webkul\DAM\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Facades\Image;
use Webkul\DAM\Helpers\AssetHelper;

/**
 * Class FileController
 *
 * This controller manages file operations on a private storage disk, including creating,
 * updating, fetching, and deleting files. It also handles image-specific functionalities
 * such as generating thumbnails and previews. The operations are performed with necessary
 * checks for file existence and user authentication, ensuring secure and efficient management
 * of digital assets. Non-image files and unsupported operations return appropriate error responses.
 */
class FileController
{
    /**
     * Create a new file in the private storage.
     *
     * This method generates a random directory name, saves the uploaded file into
     * the 'private' disk storage, and returns the file path in a JSON response.
     */
    public function createFile(Request $request)
    {
        $directory = Str::random(10).'/files';
        $path = Storage::disk('private')->put($directory, $request->file);

        return response()->json(['path' => $path]);
    }

    /**
     * Remove the specified file from storage.
     *
     * This method attempts to delete a file from the private disk storage. If the file exists,
     * it is deleted, and a success response is returned. If the file is not found, an error response is returned.
     */
    public function deleteFile(Request $request)
    {
        if (Storage::disk('private')->exists($request->path)) {
            Storage::disk('private')->delete($request->path);

            return response()->json(['status' => 'File deleted']);
        } else {
            return response()->json(['error' => 'File not found'], 404);
        }
    }

    /**
     * Update the specified file.
     *
     * This method checks if the requested file exists in the private disk storage
     * and updates it with a new one provided in the request. If the file exists,
     * it deletes the old file and stores the new one in a randomly generated directory.
     * If the file doesn't exist, it returns an error response.
     */
    public function updateFile(Request $request)
    {
        if (Storage::disk('private')->exists($request->path)) {

            Storage::disk('private')->delete($request->path);

            $directory = Str::random(10).'/files';

            $newPath = Storage::disk('private')->put($directory, $request->file);

            return response()->json(['new_path' => $newPath]);
        } else {
            return response()->json(['error' => 'File not found'], 404);
        }
    }

    /**
     * Fetch a file from the private storage.
     *
     * This method retrieves the specified file if it exists in the private disk storage
     * and returns its content with the correct MIME type. If the file does not exist,
     * an error response is returned.
     */
    public function fetchFile(string $path)
    {
        if (Storage::disk('private')->exists($path)) {
            $mimeType = Storage::disk('private')->mimeType($path);

            return response(Storage::disk('private')->get($path), 200)->header('Content-Type', $mimeType);
        } else {
            return response()->json(['error' => 'File not found'], 404);
        }
    }

    /**
     * Generate and return a 300px thumbnail of an image file.
     *
     * This method first checks if the user is authenticated. If authentication passes,
     * it verifies the existence of a thumbnail for the specified path. If a thumbnail
     * does not exist and the original file is an image, it creates a new thumbnail
     * with a width of 300 pixels, maintaining the aspect ratio. Non-image files will cause a 404 error.
     */
    public function thumbnail()
    {
        if (! Auth::check()) {
            return abort(403, 'Unauthorized');
        }

        $path = urldecode(request()->path);
        $thumbnailPath = 'thumbnails/'.$path;

        if ($this->isImageFile($thumbnailPath)) {
            return $this->getFileResponse($thumbnailPath);
        }

        if ($this->isImageFile($path)) {
            try {
                $image = $this->resizeImage(Storage::disk('private')->get($path), 300);
                Storage::disk('private')->put($thumbnailPath, (string) $image->encode());

                return response($image->encode(), 200)->header('Content-Type', Storage::disk('private')->mimeType($path));
            } catch (\Intervention\Image\Exception\NotReadableException $e) {

            }
        }

        return $this->getDefaultThumbnailImage($path);
    }

    /**
     * Checks if the given file path points to an image file.
     *
     * This method determines if the file at the specified path is an image by
     * examining its MIME type. SVG images are specifically excluded from being
     * considered as image files within this context.
     */
    private function isImageFile($path)
    {
        if (Storage::disk('private')->exists($path)) {
            $mimeType = Storage::disk('private')->mimeType($path);

            return Str::startsWith($mimeType, 'image/') && $mimeType !== 'image/svg+xml';
        }

        return false;
    }

    /**
     * Returns a response containing the requested file.
     *
     * This method retrieves a file from the storage and prepares a HTTP response with
     * the file content as well as its MIME type.
     */
    private function getFileResponse($path)
    {
        $file = Storage::disk('private')->get($path);
        $mimeType = Storage::disk('private')->mimeType($path);

        return response($file, 200)->header('Content-Type', $mimeType);
    }

    /**
     * Resize the given image file to the specified width while maintaining the aspect ratio.
     *
     * This method takes a raw image file content and resizes it to the specified width, ensuring
     * that the aspect ratio is maintained during the process. It utilizes the Intervention Image
     * library to perform the resizing operation.
     */
    private function resizeImage($file, $width)
    {
        return Image::make($file)->resize($width, null, function ($constraint) {
            $constraint->aspectRatio();
        });
    }

    /**
     * Generate and return a preview of an image file at a specified custom size.
     *
     * This function checks if the user is authenticated before processing. It first verifies if
     * a preview of the specified size already exists for the given file path. If a preview exists,
     * it returns the existing preview. If the preview does not exist, and the original file is an image,
     * the method resizes the image to the specified width while maintaining the aspect ratio and stores
     * the resized image for future requests. The function also returns the original media file if it
     * matches certain types such as SVG, PDF, video, or audio formats. Unauthorized access or non-existence
     * of the file results in respective HTTP error responses.
     */
    public function preview()
    {
        if (! Auth::check()) {
            return abort(403, 'Unauthorized');
        }

        $customSize = intval(request()->get('size'));

        // Determine the maximum supported image preview size
        $maxSize = 1920; // Example maximum size

        // Validate custom size against the maximum allowed size
        $customSize = min($maxSize, $customSize);
        $path = urldecode(request()->path);
        $previewDirectory = 'preview/'.$customSize;

        $previewPath = $previewDirectory.'/'.$path;

        if (Storage::disk('private')->exists($previewPath)) {
            return $this->getFileResponse($previewPath);
        }

        if (Storage::disk('private')->exists($path)) {
            $mimeType = Storage::disk('private')->mimeType($path);

            if ($this->isImageFile($path) && $customSize > 0) {
                try {
                    $image = $this->resizeImage(Storage::disk('private')->get($path), $customSize);
                    Storage::disk('private')->put($previewPath, (string) $image->encode());

                    return response($image->encode(), 200)->header('Content-Type', $mimeType);
                } catch (\Intervention\Image\Exception\NotReadableException $e) {
                    // Log or handle exception
                }
            } elseif ($this->isSupportedMediaFile($mimeType)) {
                return response(Storage::disk('private')->get($path), 200)->header('Content-Type', $mimeType);
            }
        }

        return $this->getDefaultPreviewImage($path);
    }

    /**
     * Check if the MIME type corresponds to a supported media file
     *
     * Supported types include SVG images, PDF, video, and audio formats.
     */
    private function isSupportedMediaFile($mimeType)
    {
        return Str::startsWith($mimeType, 'image/') ||
               Str::startsWith($mimeType, 'application/pdf') ||
               Str::startsWith($mimeType, 'video/') ||
               Str::startsWith($mimeType, 'audio/');
    }

    /**
     * Retrieve a default image based on the file type and the directory prefix.
     *
     * This helper method selects a specific placeholder image for non-image files.
     * It fetches the placeholder image from the public directory and returns it as an
     * HTTP response with its corresponding MIME type. If the placeholder image is not found,
     * a 404 error is returned.
     *
     * @param  string  $path
     * @param  string  $directoryPrefix
     * @return \Illuminate\Http\Response
     */
    private function getDefaultImage($path, $directoryPrefix)
    {
        $extension = File::extension(basename($path));
        $type = AssetHelper::getFileTypeUsingExtension($extension);
        $placeholderPath = 'dam/'.$directoryPrefix.'/'.$type.'.svg';

        if (Storage::disk('public')->exists($placeholderPath)) {
            $mimeType = Storage::disk('public')->mimeType($placeholderPath);
            $fileContent = Storage::disk('public')->get($placeholderPath);

            return response($fileContent, 200)
                ->header('Content-Type', $mimeType);
        }

        return response()->json(['error' => trans('Placeholder not found')], 404);
    }

    /**
     * Retrieve a default thumbnail image based on the file type.
     *
     * This method uses the helper to fetch a thumbnail placeholder.
     *
     * @param  string  $path
     * @return \Illuminate\Http\Response
     */
    public function getDefaultThumbnailImage($path)
    {
        return $this->getDefaultImage($path, 'grid');
    }

    /**
     * Retrieve a default preview image based on the file extension.
     *
     * This method uses the helper to fetch a preview placeholder.
     *
     * @param  string  $path
     * @return \Illuminate\Http\Response
     */
    public function getDefaultPreviewImage($path)
    {
        return $this->getDefaultImage($path, 'preview');
    }
}
