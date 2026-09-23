<?php

namespace Spokospace\OpsNotify\Http;

use Spokospace\OpsNotify\Support\BotAvatars;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Serves preset avatar thumbnails for the picker. Static package images, safe to serve publicly. */
class AvatarThumbnailController
{
    public function __invoke(string $key): BinaryFileResponse
    {
        abort_unless(BotAvatars::exists($key), 404);

        return response()->file(BotAvatars::path($key, thumbnail: true), [
            'Cache-Control' => 'public, max-age=604800',
        ]);
    }
}
