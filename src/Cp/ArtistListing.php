<?php

namespace Kraenkvisuell\StatamicReps\Cp;

use Kraenkvisuell\StatamicReps\Portfolio;
use Kraenkvisuell\StatamicReps\Reps;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\Asset;
use Statamic\Facades\Collection;
use Statamic\Statamic;

/**
 * Columns of the artists listing in the CP, like the old Nova index: the name with /slug
 * underneath, "Copy Link" buttons (feature artist_copy_links), portfolio and start page images.
 * Computed values of the reps_* fields in the artist blueprint, only calculated for CP requests.
 */
class ArtistListing
{
    public static function register(): void
    {
        $columns = [
            'reps_name' => fn (Entry $artist) => static::name($artist),
            'reps_copy_links' => fn (Entry $artist) => static::copyLinks($artist),
            'reps_portfolio_images' => fn (Entry $artist) => static::paths(static::portfolioImages($artist)),
            'reps_startpage_images' => fn (Entry $artist) => static::paths(Portfolio::flagged($artist, 'is_startpage_image')->pluck('asset')->all()),
        ];

        foreach ($columns as $handle => $callback) {
            Collection::computed('artists', $handle, fn (Entry $artist) => Statamic::isCpRoute() ? $callback($artist) : null);
        }
    }

    public static function name(Entry $artist): string
    {
        $html = '<a href="'.e($artist->editUrl()).'" class="title-index-field">'.e((string) $artist->get('title')).'</a>'
            .'<div class="text-xs text-gray-500">/'.e((string) $artist->slug()).'</div>';

        if (Reps::feature('external_artists') && $artist->get('is_external')) {
            $html .= '<div class="text-xs text-gray-500">('.e(__('External (no portfolio page)')).')</div>';
        }

        return $html;
    }

    /** The old buttons copied a:<id> / b:<id> with the Nova id; new artists use their entry id. */
    public static function copyLinks(Entry $artist): string
    {
        $id = $artist->root()->get('legacy_id') ?: $artist->root()->id();

        return collect(['a' => 'Copy Link', 'b' => 'Copy Link 2'])
            ->map(fn ($label, $prefix) => '<button type="button" data-reps-copy="'.e("$prefix:$id").'"'
                .' style="display:block;margin:2px 0;padding:2px 8px;border-radius:4px;font-size:12px;white-space:nowrap;background:#2563eb;color:#fff">'
                .e($label).'</button>')
            ->implode('');
    }

    /** The artist's own portfolio image first, then the works flagged as portfolio images. */
    public static function portfolioImages(Entry $artist): array
    {
        $limit = (int) Reps::config('cp.artist_portfolio_images', 3);
        $own = collect((array) $artist->get('portfolio_image'))->filter()->map(fn ($path) => static::asset($path));

        return $own->merge(Portfolio::flagged($artist, 'is_portfolio_image')->pluck('asset'))
            ->filter()
            ->unique(fn ($asset) => $asset->id())
            ->take($limit)
            ->values()
            ->all();
    }

    protected static function asset(string $path)
    {
        return Asset::find(Reps::config('assets.container').'::'.$path);
    }

    /** Asset paths for the assets fieldtype's listing thumbnails. */
    protected static function paths(array $assets): array
    {
        return collect($assets)->filter()->map(fn ($asset) => $asset->path())->values()->all();
    }
}
