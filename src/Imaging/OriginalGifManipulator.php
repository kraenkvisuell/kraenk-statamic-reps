<?php

namespace Kraenkvisuell\StatamicReps\Imaging;

use Statamic\Contracts\Assets\Asset;
use Statamic\Facades\Asset as Assets;
use Statamic\Imaging\GlideImageManipulator;

/**
 * Animated GIFs keep their original file, like the old Nova sites (nova_cms_image() skipped the
 * resizing for GIFs): Glide re-encodes every frame, takes minutes per GIF, upscales small ones to
 * the requested width and produces files many times larger than the original – too large for a
 * single upload to Bunny. Asking for a format explicitly (`fm`, e.g. a JPG of the first frame for
 * a PDF) still goes through Glide.
 */
class OriginalGifManipulator extends GlideImageManipulator
{
    public function build()
    {
        // assets without a public URL (a private disk) still go through Glide
        if (! array_key_exists('fm', $this->params ?? []) && ($url = $this->gif()?->url())) {
            return $url;
        }

        return parent::build();
    }

    protected function gif(): ?Asset
    {
        $item = $this->item;

        if (is_string($item) && str_contains($item, '::')) {
            $item = Assets::find($item);
        }

        return $item instanceof Asset && strtolower((string) $item->extension()) === 'gif' ? $item : null;
    }
}
