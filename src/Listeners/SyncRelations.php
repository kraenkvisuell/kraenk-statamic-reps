<?php

namespace Kraenkvisuell\StatamicReps\Listeners;

use Kraenkvisuell\StatamicReps\Reps;
use Statamic\Events\EntrySaved;
use Statamic\Facades\Entry;

/**
 * Keeps the inverse side of the parent → children relations editors maintain on the parent:
 * an artist's `slideshows` set each slideshow's `artist`; a special category's `projects` set
 * each project's `special_category`. With `artist_categories_order`, the artist's categories
 * follow its slideshows (new ones appended, the editor's order kept).
 */
class SyncRelations
{
    protected const RELATIONS = [
        'artists' => ['slideshows', 'artist'],
        'special_categories' => ['projects', 'special_category'],
    ];

    // the importer sets both sides itself
    public static bool $enabled = true;

    public function handle(EntrySaved $event): void
    {
        if (! static::$enabled) {
            return;
        }

        $entry = $event->entry;
        $collection = $entry->collectionHandle();

        if (! isset(static::RELATIONS[$collection]) || ! $entry->isRoot()) {
            return;
        }

        [$childrenField, $parentField] = static::RELATIONS[$collection];

        foreach ((array) $entry->get($childrenField, []) as $childId) {
            $child = Entry::find($childId);

            // also re-saved when the parent's slug changed, so the child's URI follows
            if ($child && ($child->get($parentField) !== $entry->id() || ! str_contains((string) $child->uri(), '/'.$entry->slug().'/'))) {
                $child->set($parentField, $entry->id())->saveQuietly();
            }
        }

        if ($collection === 'artists' && Reps::feature('artist_categories_order')) {
            $this->syncArtistCategories($entry);
        }
    }

    protected function syncArtistCategories($artist): void
    {
        $used = Entry::query()->whereIn('id', (array) $artist->get('slideshows', []))->get()
            ->flatMap(fn ($slideshow) => (array) $slideshow->get('categories', []))
            ->unique()->values()->all();

        $current = (array) $artist->get('categories', []);
        $categories = array_values(array_merge(array_intersect($current, $used), array_diff($used, $current)));

        if ($categories !== $current) {
            $artist->set('categories', $categories)->saveQuietly();
        }
    }
}
