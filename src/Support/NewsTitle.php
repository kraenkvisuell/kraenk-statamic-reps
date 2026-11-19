<?php

namespace Kraenkvisuell\StatamicReps\Support;

use Kraenkvisuell\StatamicReps\Portfolio;
use Statamic\Contracts\Entries\Entry;

/**
 * The generated title of a news item with the feature news_headline: "Artist / Project" (the
 * project's artist when the item has none), else the headline or subtitle, else "News". The text
 * on the item is the headline. One rule for the CP (the collection's title_format renders the
 * computed reps_title) and the import, so a re-sync compares the same value.
 */
class NewsTitle
{
    public static function of(Entry $news): string
    {
        return static::make($news->get('artist'), $news->get('slideshow'), $news->get('headline') ?: $news->get('subtitle'));
    }

    public static function make(mixed $artist, mixed $slideshow, ?string $fallback = null): string
    {
        $slideshow = Portfolio::artist(static::first($slideshow));
        $artist = Portfolio::artist(static::first($artist))
            ?? ($slideshow ? Portfolio::artist(static::first($slideshow->root()->get('artist'))) : null);

        $title = collect([$artist?->get('title'), $slideshow?->get('title')])
            ->map(fn ($part) => trim((string) $part))
            ->filter()
            ->implode(' / ');

        return $title ?: (trim((string) $fallback) ?: 'News');
    }

    /** Entries fields hold an id, or a list of ids when saved with several. */
    protected static function first(mixed $value): mixed
    {
        return is_array($value) ? ($value[0] ?? null) : $value;
    }
}
