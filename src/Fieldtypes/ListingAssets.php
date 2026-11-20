<?php

namespace Kraenkvisuell\StatamicReps\Fieldtypes;

use Kraenkvisuell\StatamicBunnyAssets\Fieldtypes\CdnThumbnailAssets;

/**
 * The assets columns of the CP artist list with cp.listing_videos: videos play (muted, looped)
 * instead of showing a file icon, everything else is Statamic's own assets cell (index component
 * in resources/js/cp.js). AdjustBlueprints swaps the reps_*_images columns to this type. Image
 * thumbnails come from the Glide CDN once generated (statamic-bunny-assets).
 */
class ListingAssets extends CdnThumbnailAssets
{
    protected static $handle = 'reps_listing_assets';

    protected $selectable = false;

    protected const VIDEOS = ['mp4', 'm4v', 'mov', 'webm', 'ogv'];

    public function preProcessIndex($data)
    {
        $index = parent::preProcessIndex($data);

        $index['assets'] = collect($index['assets'])
            ->map(fn (array $asset) => $asset + ['is_video' => in_array(strtolower((string) $asset['extension']), static::VIDEOS, true)])
            ->values()
            ->all();

        return $index;
    }
}
