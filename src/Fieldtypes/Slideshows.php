<?php

namespace Kraenkvisuell\StatamicReps\Fieldtypes;

use Kraenkvisuell\StatamicReps\Portfolio;
use Kraenkvisuell\StatamicReps\Reps;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Asset;
use Statamic\Facades\AssetContainer;
use Statamic\Fieldtypes\Entries;

/**
 * The artist's slideshows, with rows like the old Nova list: categories under the title, the
 * works' thumbnails, the number of works, a position dropdown and actions (publish, show in the
 * overview). The row is `reps-slideshow-item` in resources/js/cp.js; the actions post to
 * Http\Controllers\Cp\SlideshowItemController.
 */
class Slideshows extends Entries
{
    protected static $handle = 'reps_slideshows';

    protected $selectable = false;

    protected $itemComponent = 'reps-slideshow-item';

    protected function toItemArray($id)
    {
        $item = parent::toItemArray($id);

        if (($item['invalid'] ?? false) || ! $entry = $this->findEntry($id)) {
            return $item;
        }

        return array_merge($item, static::details($entry));
    }

    /** The extra data of a row (also returned by the actions to refresh it). */
    public static function details(EntryContract $slideshow): array
    {
        $root = $slideshow->root();
        $works = collect($root->get('works', []))->filter(fn ($work) => $work['enabled'] ?? true)->values();
        $container = Reps::config('assets.container');
        $assets = AssetContainer::find($container);

        $thumbnails = $works->pluck('file')->filter()
            ->take((int) Reps::config('cp.slideshow_thumbnails', 99))
            ->map(function ($path) use ($container, $assets) {
                $id = "$container::$path";

                if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'mp4') {
                    return ['video' => Asset::find($id)?->url()];
                }

                // makeAsset: no lookup of the file, the thumbnail URL only needs the cached meta
                // (Glide CDN once generated, see Imaging\CdnThumbnailAsset)
                return ['image' => $assets
                    ? $assets->makeAsset($path)->thumbnailUrl('small')
                    : cp_route('assets.thumbnails.show', ['encoded_asset' => base64_encode($id), 'size' => 'small'])];
            })
            ->filter(fn ($thumbnail) => array_filter($thumbnail))
            ->values()->all();

        $categories = collect(Portfolio::entries(array_values(array_filter((array) $root->get('categories', [])))))
            ->map(fn ($category) => (string) $category->value('title'))->all();

        return [
            'reps' => [
                'categories' => $categories,
                'thumbnails' => $thumbnails,
                'works' => $works->count(),
                'works_label' => Reps::label($works->count() === 1 ? 'work' : 'works'),
                'published' => $slideshow->published(),
                // null where the site has no artist overview
                'visible_in_overview' => Reps::feature('overview') ? $root->get('is_visible_in_overview', true) !== false : null,
                'overview_label' => 'Show in the artist overview',
            ],
        ];
    }
}
