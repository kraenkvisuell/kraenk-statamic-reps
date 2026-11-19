<?php

namespace Kraenkvisuell\StatamicReps\Cp;

use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\Collection;
use Statamic\Statamic;

/**
 * Colour columns of the CP lists, like the Nova index: a swatch with the hex code. Artists
 * (reps_bgcolor in Cp\ArtistListing, feature artist_bgcolor), pages (feature page_bgcolor) and
 * news (feature work_bgcolor);
 * computed values of reps_html fields, only calculated for CP requests.
 */
class ColorColumn
{
    public static function register(): void
    {
        Collection::computed('pages', 'reps_bgcolor', fn (Entry $page) => Statamic::isCpRoute() ? static::html($page->value('bg_color')) : null);
        Collection::computed('news', 'reps_bgcolor', fn (Entry $news) => Statamic::isCpRoute() ? static::html($news->value('bgcolor')) : null);
    }

    public static function html(mixed $color): string
    {
        $color = strtoupper(trim((string) $color));

        if (! preg_match('/^#[0-9A-F]{3,8}$/', $color)) {
            return '';
        }

        return '<div class="flex items-center gap-2">'
            .'<span class="inline-block size-4 shrink-0 rounded-sm border border-black/10" style="background-color: '.$color.'"></span>'
            .'<span class="font-mono text-xs">'.$color.'</span></div>';
    }
}
