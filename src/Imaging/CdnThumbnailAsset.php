<?php

namespace Kraenkvisuell\StatamicReps\Imaging;

use Kraenkvisuell\StatamicReps\Reps;
use Statamic\Assets\Asset;
use Statamic\Facades\Glide;
use Statamic\Imaging\ImageGenerator;

/**
 * CP thumbnails straight from the Glide cache's CDN once they exist (cp.cdn_thumbnails).
 *
 * Statamic's thumbnail route (/cp/thumbnails/…) is a PHP request per image, even when the
 * thumbnail has long been generated: it boots Laravel, checks the login and streams the file
 * back from the Glide cache disk (Bunny Storage), about half a second each, and the CDN in front
 * of the site doesn't cache it (no file extension). A list with dozens of images filled in batch
 * by batch. The thumbnail's path is in Glide's cache store as soon as the route has generated it
 * once, so from then on the CP links the file on the Glide CDN: no PHP, cached by the CDN and the
 * browser. Thumbnails not generated yet (and images too large for one) still go through the route.
 *
 * `?v=` (the asset's modification time) gives a replaced image a new URL in the browser; the
 * Glide path itself stays the same (it is hashed from the source path and the params).
 */
class CdnThumbnailAsset extends Asset
{
    public function thumbnailUrl($preset = null)
    {
        return $this->cdnThumbnailUrl($preset) ?? parent::thumbnailUrl($preset);
    }

    protected function cdnThumbnailUrl(?string $preset): ?string
    {
        if (! Reps::config('cp.cdn_thumbnails', true) || ! Glide::shouldServeDirectly() || ! $this->isImage() || $this->isSvg()) {
            return null;
        }

        // the params Statamic's ThumbnailController generates with
        $params = $preset ? ['p' => "cp_thumbnail_{$preset}_{$this->orientation()}"] : [];
        $path = Glide::cacheStore()->get(ImageGenerator::manipulationCacheKey('asset', $this, $params));

        if (! is_string($path) || $path === '') {
            return null;
        }

        $url = Glide::url().'/'.implode('/', array_map('rawurlencode', explode('/', ltrim($path, '/'))));

        return ($modified = $this->lastModified()) ? $url.'?v='.$modified->timestamp : $url;
    }
}
