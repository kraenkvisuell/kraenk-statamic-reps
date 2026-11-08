<?php

namespace Kraenkvisuell\StatamicReps;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Asset;
use Statamic\Facades\Blink;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;

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
    public static function site(): string
    {
        return Site::current()->handle();
    }

    /** The entry in the current site (falls back to the origin when it has no localization there). */
    public static function local(?EntryContract $entry): ?EntryContract
    {
        if (! $entry) {
            return null;
        }

        return $entry->root()->in(static::site()) ?? $entry->root();
    }

    /** Entries by (origin) ids in the given order, localized to the current site, published only. */
    public static function entries(array $ids): Collection
    {
        $ids = array_values(array_filter($ids, fn ($id) => is_string($id) && Str::isUuid($id)));

        if (! $ids) {
            return collect();
        }

        return Entry::query()->whereIn('id', $ids)->get()
            ->sortBy(fn ($e) => array_search($e->id(), $ids))
            ->map(fn ($e) => static::local($e))
            ->filter(fn ($e) => $e && $e->published())
            ->values();
    }

    public static function artist(mixed $artist): ?EntryContract
    {
        if ($artist instanceof Collection) {
            $artist = $artist->first();
        }

        if ($artist instanceof EntryContract) {
            return $artist;
        }

        return is_string($artist) && Str::isUuid($artist) ? static::local(Entry::find($artist)) : null;
    }

    // raw (non-localized) value of a reference field, read from the origin
    protected static function ids(EntryContract $entry, string $field): array
    {
        return array_values(array_filter((array) $entry->root()->get($field, [])));
    }

    /** The artist's published slideshows in the artist's order. */
    public static function slideshows(EntryContract $artist, bool $onlyVisible = false): Collection
    {
        $slideshows = Blink::once('reps-slideshows-'.$artist->root()->id().'-'.static::site(),
            fn () => static::entries(static::ids($artist, 'slideshows')));

        return $onlyVisible && Reps::feature('overview')
            ? $slideshows->filter(fn ($s) => $s->root()->get('is_visible_in_overview', true) !== false)->values()
            : $slideshows;
    }

    /** All work items of a slideshow. */
    public static function items(EntryContract $slideshow, ?EntryContract $artist = null): Collection
    {
        $slideshow = static::local($slideshow);

        return Blink::once('reps-items-'.$slideshow->id(), function () use ($slideshow, $artist) {
            $artist ??= static::artist(static::ids($slideshow, 'artist')[0] ?? null);

            return collect($slideshow->root()->get('works', []))
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

        // translations of the work texts are stored on the row (title_<site> …), see work_common
        $site = static::site();
        foreach (['title', 'caption', 'description'] as $field) {
            if (! empty($work["{$field}_{$site}"])) {
                $work[$field] = $work["{$field}_{$site}"];
            }
        }

        return [
            'key' => $slideshow->root()->id().':'.$work['id'],
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
        $order = Reps::feature('artist_categories_order') ? static::ids($artist, 'categories') : [];
        $used = $slideshows->flatMap(fn ($s) => static::ids($s, 'categories'))->unique()->values()->all();
        $ids = array_values(array_unique(array_merge(array_intersect($order, $used), $used)));

        return static::entries($ids)->map(fn ($category) => [
            'category' => $category,
            'slideshows' => $slideshows
                ->filter(fn ($s) => in_array($category->root()->id(), static::ids($s, 'categories'), true))
                ->map(fn ($s) => [
                    'slideshow' => $s,
                    'items' => $workLimit ? static::items($s, $artist)->take($workLimit)->values()->all() : static::items($s, $artist)->all(),
                ])
                ->values()->all(),
        ])->filter(fn ($group) => $group['slideshows'])->values();
    }

    /**
     * Published slideshows of a category: in the category's own order (slideshow_order, imported from
     * the old site) and the others newest first.
     */
    public static function categorySlideshows(EntryContract $category): Collection
    {
        $category = $category->root();

        return Blink::once('reps-category-slideshows-'.$category->id().'-'.static::site(), function () use ($category) {
            $order = array_flip(static::ids($category, 'slideshow_order'));

            $origins = Entry::query()
                ->where('collection', 'slideshows')
                ->where('site', Site::default()->handle())
                ->whereJsonContains('categories', $category->id())
                ->get()
                ->sortBy(fn ($s) => [isset($order[$s->id()]) ? 0 : 1, $order[$s->id()] ?? -($s->date()?->timestamp ?? 0)])
                ->values();

            return $origins->map(fn ($s) => static::local($s))->filter(fn ($s) => $s && $s->published())->values();
        });
    }

    /**
     * Works of a category grouped by artist: by default the works chosen to represent the artist in
     * that category (represents_in), else the first $fallback works of the artist's slideshows in it.
     * Only published artists; [['artist' => Entry, 'items' => [...]]], ordered by artist name.
     */
    public static function categoryArtists(EntryContract $category, int $fallback = 0, ?EntryContract $discipline = null): Collection
    {
        return static::categorySlideshows($category)
            ->groupBy(fn ($s) => static::ids($s, 'artist')[0] ?? '')
            ->map(function ($slideshows, $artistId) use ($category, $fallback, $discipline) {
                $artist = static::artist($artistId);

                if (! $artist || ! $artist->published() || $artist->root()->get('is_external')) {
                    return null;
                }

                if ($discipline && ! in_array($discipline->root()->id(), static::ids($artist, 'disciplines'), true)) {
                    return null;
                }

                $order = static::ids($artist, 'slideshows');
                $items = $slideshows
                    ->sortBy(fn ($s) => array_search($s->root()->id(), $order))
                    ->flatMap(fn ($s) => static::items($s, $artist));
                $chosen = $items->filter(fn ($item) => in_array($category->root()->id(), (array) ($item['work']['represents_in'] ?? []), true));

                if ($chosen->isEmpty() && $fallback) {
                    $chosen = $items->filter(fn ($item) => $item['asset'])->take($fallback);
                }

                return $chosen->isEmpty() ? null : ['artist' => $artist, 'items' => $chosen->values()->all()];
            })
            ->filter()
            ->sortBy(fn ($group) => Str::lower(Str::ascii($group['artist']->get('title'))))
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
                        ? in_array(Entry::find($categoryId)?->root()->id() ?? $categoryId, (array) ($item['work']['overview_categories'] ?? []), true)
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
