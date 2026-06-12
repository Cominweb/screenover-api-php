<?php

namespace Screenover\Api\Media;

use Screenover\Api\Exception\ApiException;
use Screenover\Api\ScreenoverApi;

/**
 * Orchestrates the multi-step media upload flow used by ScreenOver:
 *
 *   1. POST /api/media/get-upload-url        -> signed (resumable) GCS URL
 *   2. PUT  <signed url>                      -> upload the binary
 *   3. POST /api/media                        -> create the media (source.type = upload)
 *   4. POST /api/media/set-upload-filename    -> finalise (size, mimeType, EXIF, thumbnails)
 */
class Uploader
{
    public function __construct(private ScreenoverApi $api)
    {
    }

    /**
     * Upload a local file and create the associated media document.
     *
     * @param array<string,mixed> $datas Extra media fields (title, description, project, tags...).
     * @return array<string,mixed> The created (and finalised) media document.
     *
     * @throws ApiException on any failure during the flow
     */
    public function upload(string $filePath, array $datas = []): array
    {
        if (!is_file($filePath)) {
            throw new ApiException('File not found: ' . $filePath);
        }

        $filename = basename($filePath);

        // 1) Ask the backend for a signed upload URL.
        $signed = $this->api->call('POST', 'media/get-upload-url', ['filename' => $filename]);
        if (empty($signed['url']) || empty($signed['filename'])) {
            throw new ApiException('Invalid get-upload-url response');
        }
        $contentType = $signed['mimeType'] ?? 'application/octet-stream';

        // 2) Upload the binary to GCS.
        $this->api->getHttpClient()->putFile($signed['url'], $filePath, $contentType);

        // 3) Create the media document (source.type = upload).
        $media = $this->api->post('media', array_merge([
            'title' => $datas['title'] ?? $filename,
            'source' => ['type' => 'upload'],
        ], $datas));

        $mediaId = $media['id'] ?? null;
        if ($mediaId === null) {
            throw new ApiException('Media creation did not return an id');
        }

        // 4) Finalise: links the uploaded object, computes size/mimeType/EXIF/thumbnails.
        $final = $this->api->call('POST', 'media/set-upload-filename', [
            'id' => $mediaId,
            'filename' => $signed['filename'],
        ]);

        if (isset($final['media']) && is_array($final['media'])) {
            return $final['media'];
        }

        return $media;
    }
}
