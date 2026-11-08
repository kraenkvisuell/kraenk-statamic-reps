<?php

namespace Kraenkvisuell\StatamicReps;

use Illuminate\Support\Collection;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Asset;
use Statamic\Facades\Blink;
use Statamic\Facades\Entry;

/**
 * Queries over the works that live inside slideshows: an artist's flagged images, the works of a
 * category, an artist page grouped by category. Every work comes back as a flat item:
 *
 *   key        "<slideshow id>:<work row id>" (lightbox key)
 *   work       the row (title, caption, type, file, embed_url …)
 *   asset      the Asset of `file` (null for text boxes / embeds without preview)
 *   slideshow  the slideshow entry, artist the artist entry
 *   position   1-based position in the slideshow, url the slideshow URL with /position
 *   ratio      width / height (custom_ratio, embed_ratio, the asset's, else 16:9)
 *   is_video   mp4 asset, is_embed embed row, is_gif gif asset
 */
class Portfolio
{
    public static function artist(mixed $artist): ?EntryContract
    {
        if ($artist instanceof Collection) {
            $artist = $artist->first();
        }

        if ($artist instanceof EntryContract) {
            return $artist;
        }

        return is_string($artist) && $artist !== '' ? Entry::find($artist) : null;
    }

    /** The artist's published slideshows in the artist's order. */
    public static function slideshows(EntryContract $artist, bool $onlyVisible = false): Collection
    {
        $ids = (array) $artist->get('slideshows', []);

        $slideshows = Blink::once("reps-slideshows-{$artist->id()}", fn () => Entry::query()
            ->whereIn('id', $ids)
            ->where('published', true)
            ->get()
            ->sortBy(fn ($s) => array_search($s->id(), $ids))
            ->values());

        return $onlyVisible && Reps::feature('overview')
            ? $slideshows->filter(fn ($s) => $s->get('is_visible_in_overview', true) !== false)->values()
            : $slideshows;
    }

    /** All work items of a slideshow. */
    public static function items(EntryContract $slideshow, ?EntryContract $artist = null): Collection
    {
        return Blink::once("reps-items-{$slideshow->id()}", function () use ($slideshow, $artist) {
            $artist ??= static::artist($slideshow->get('artist'));

            return collect($slideshow->get('works', []))
                ->filter(fn ($work) => $work['enabled'] ?? true)
                ->values()
                ->map(fn ($work, $i) => static::item($work, $i + 1, $slideshow, $artist));
        });
    }

    public static function item(array $work, int $position, EntryContract $slideshow, ?EntryContract $artist): array
    {
        $asset = ! empty($work['file']) ? Asset::find(Reps::config('assets.container').'::'.$work['file']) : null;
        $extension = $asset ? strtolower($asset->extension()) : null;
        $isEmbed = ($work['type'] ?? null) === 'embed';

        return [
            'key' => $slideshow->id().':'.$work['id'],
            'id' => $work['id'],
            'work' => $work,
            'title' => $work['title'] ?? null,
            'type' => $work['type'] ?? 'image',
            'asset' => $asset,
            'slideshow' => $slideshow,
            'artist' => $artist,
            'position' => $position,
            'url' => rtrim((string) $slideshow->url(), '/').'/'.$position,
            'ratio' => static::ratio($work, $asset),
            'is_video' => $extension === 'mp4',
            'is_gif' => $extension === 'gif',
            'is_embed' => $isEmbed,
            'can_lightbox' => $asset && ! $isEmbed && in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true),
        ];
    }

    public static function ratio(array $work, $asset = null): float
    {
        foreach ([$work['custom_ratio'] ?? null, ($work['type'] ?? null) === 'embed' ? ($work['embed_ratio'] ?? '16:9') : null] as $value) {
            if ($value && preg_match('~^\s*([\d.]+)\s*[:/x]\s*([\d.]+)\s*$~', $value, $m) && (float) $m[2] > 0) {
                return round((float) $m[1] / (float) $m[2], 4);
            }
        }

        if ($asset && $asset->width() && $asset->height()) {
            return round($asset->width() / $asset->height(), 4);
        }

        return round(16 / 9, 4);
    }

    /**
     * Works of an artist with a flag (is_portfolio_image, is_startpage_image, show_in_overview …),
     * in slideshow order. With $fallback the artist's first works fill up to $limit.
     */
    public static function flagged(EntryContract $artist, string $flag, ?int $limit = null, bool $fallback = false): Collection
    {
        $all = static::slideshows($artist)->flatMap(fn ($s) => static::items($s, $artist));
        $flagged = $all->filter(fn ($item) => ! empty($item['work'][$flag]));

        if ($fallback && $flagged->isEmpty()) {
            $flagged = $all->filter(fn ($item) => $item['asset']);
        }

        return $limit ? $flagged->take($limit)->values() : $flagged->values();
    }

    /**
     * The artist's categories (artist order with artist_categories_order, else first use), each
     * with the artist's slideshows in it and their works:
     * [['category' => Entry, 'slideshows' => [['slideshow' => Entry, 'items' => [...]]]]].
     */
    public static function artistCategories(EntryContract $artist, bool $onlyVisible = true, ?int $workLimit = null): Collection
    {
        $slideshows = static::slideshows($artist, $onlyVisible);
        $order = Reps::feature('artist_categories_order') ? (array) $artist->get('categories', []) : [];
        $used = $slideshows->flatMap(fn ($s) => (array) $s->get('categories', []))->unique()->values()->all();
        $ids = array_values(array_unique(array_merge(array_intersect($order, $used), $used)));

        $categories = Entry::query()->whereIn('id', $ids)->where('published', true)->get()
            ->sortBy(fn ($c) => array_search($c->id(), $ids))->values();

        return $categories->map(fn ($category) => [
            'category' => $category,
            'slideshows' => $slideshows
                ->filter(fn ($s) => in_array($category->id(), (array) $s->get('categories', []), true))
                ->map(fn ($s) => [
                    'slideshow' => $s,
                    'items' => $workLimit ? static::items($s, $artist)->take($workLimit)->values()->all() : static::items($s, $artist)->all(),
                ])
                ->values()->all(),
        ])->filter(fn ($group) => $group['slideshows'])->values();
    }

    /** Published slideshows of a category, newest first (the old sites' category order). */
    public static function categorySlideshows(EntryContract $category): Collection
    {
        return Blink::once("reps-category-slideshows-{$category->id()}", fn () => Entry::query()
            ->where('collection', 'slideshows')
            ->where('published', true)
            ->whereJsonContains('categories', $category->id())
            ->orderBy('date', 'desc')
            ->get());
    }

    /**
     * Works of a category grouped by artist: by default the works chosen to represent the artist in
     * that category (represents_in), else the first $fallback works of the artist's slideshows in it.
     * Only published artists; [['artist' => Entry, 'items' => [...]]], ordered by artist name.
     */
    public static function categoryArtists(EntryContract $category, int $fallback = 0, ?EntryContract $discipline = null): Collection
    {
        return static::categorySlideshows($category)
            ->groupBy(fn ($s) => (string) collect((array) $s->get('artist'))->first() ?: $s->get('artist'))
            ->map(function ($slideshows, $artistId) use ($category, $fallback, $discipline) {
                $artist = Entry::find($artistId);

                if (! $artist || ! $artist->published() || $artist->get('is_external')) {
                    return null;
                }

                if ($discipline && ! in_array($discipline->id(), (array) $artist->get('disciplines', []), true)) {
                    return null;
                }

                $order = (array) $artist->get('slideshows', []);
                $items = $slideshows
                    ->sortBy(fn ($s) => array_search($s->id(), $order))
                    ->flatMap(fn ($s) => static::items($s, $artist));
                $chosen = $items->filter(fn ($item) => in_array($category->id(), (array) ($item['work']['represents_in'] ?? []), true));

                if ($chosen->isEmpty() && $fallback) {
                    $chosen = $items->filter(fn ($item) => $item['asset'])->take($fallback);
                }

                return $chosen->isEmpty() ? null : ['artist' => $artist, 'items' => $chosen->values()->all()];
            })
            ->filter()
            ->sortBy(fn ($group) => mb_strtolower($group['artist']->get('title')))
            ->values();
    }

    /**
     * Works of the artist shown in an overview: with a category the works flagged for it
     * (overview_categories), otherwise works flagged show_in_overview in visible slideshows.
     */
    public static function overview(EntryContract $artist, ?string $categoryId = null): Collection
    {
        return static::slideshows($artist, onlyVisible: ! $categoryId)
            ->map(fn ($s) => [
                'slideshow' => $s,
                'items' => static::items($s, $artist)
                    ->filter(fn ($item) => $categoryId
                        ? in_array($categoryId, (array) ($item['work']['overview_categories'] ?? []), true)
                        : ! empty($item['work']['show_in_overview']))
                    ->values()->all(),
            ])
            ->filter(fn ($group) => $group['items'])
            ->values();
    }

    /**
     * Rows for a justified masonry: splits items into rows of $perRow (the last row takes the
     * remainder, a single leftover joins the previous row, like the old sites) and gives every item
     * its width and height in units of $width (e.g. vw), so the rows fill $width exactly.
     */
    public static function rows(array $items, int $perRow, float $width): array
    {
        $count = count($items);
        $rows = [];
        $row = [];

        foreach (array_values($items) as $i => $item) {
            $row[] = $item;
            $remaining = $count - $i - 1;

            if ((count($row) >= $perRow && $remaining !== 1) || $remaining === 0) {
                $rows[] = $row;
                $row = [];
            }
        }

        return array_map(function ($row) use ($width) {
            $ratios = array_sum(array_map(fn ($item) => (float) ($item['ratio'] ?? 1), $row)) ?: 1;
            $height = $width / $ratios;

            return [
                'height' => round($height, 4),
                'items' => array_map(fn ($item) => $item + [
                    'box_width' => round($height * (float) ($item['ratio'] ?? 1), 4),
                    'box_height' => round($height, 4),
                ], $row),
            ];
        }, $rows);
    }
}
