<?php

namespace Kraenkvisuell\StatamicReps\Listeners;

use Illuminate\Support\Str;
use Kraenkvisuell\StatamicReps\Reps;
use Kraenkvisuell\StatamicReps\Support\NewsTitle;
use Statamic\Events\EntrySaving;
use Statamic\Facades\Entry;

/**
 * With news_headline the news title is generated and hidden, so the slug can stay empty in the
 * CP: it follows the title ("artist-project"; Statamic derives it the same way), with -2, -3 …
 * when another news item has it. A slug typed in the CP is validated as unique before it gets here.
 */
class NewsSlug
{
    public function handle(EntrySaving $event): void
    {
        $entry = $event->entry;

        if ($entry->collectionHandle() !== 'news' || ! Reps::feature('news_headline')) {
            return;
        }

        $base = $entry->slug() ?: Str::slug(NewsTitle::of($entry)) ?: 'news';
        $taken = Entry::query()->where('collection', 'news')->where('slug', 'like', "$base%")->get()
            ->reject(fn ($other) => $other->id() === $entry->id())
            ->map->slug()->all();

        $slug = $base;
        for ($i = 2; in_array($slug, $taken, true); $i++) {
            $slug = "$base-$i";
        }

        $entry->slug($slug);
    }
}
