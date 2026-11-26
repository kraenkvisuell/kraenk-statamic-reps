<?php

namespace Kraenkvisuell\StatamicReps\Listeners;

use Statamic\Events\EntryDeleted;
use Statamic\Facades\Entry;

/**
 * Removes a deleted slideshow (special project) from its parent's list (the relations of
 * SyncRelations). Statamic only updates references to assets and terms, so the artist kept the
 * id and the CP showed the row as "This item is unavailable".
 */
class RemoveDeletedChildren
{
    public function handle(EntryDeleted $event): void
    {
        $entry = $event->entry;

        // the importer sets both sides itself
        if (! SyncRelations::$enabled || ! $entry->isRoot()) {
            return;
        }

        foreach (SyncRelations::RELATIONS as $collection => [$childrenField]) {
            Entry::query()
                ->where('collection', $collection)
                ->whereJsonContains($childrenField, $entry->id())
                ->get()
                ->filter(fn ($parent) => $parent->isRoot())
                ->each(fn ($parent) => $parent
                    ->set($childrenField, array_values(array_diff((array) $parent->get($childrenField, []), [$entry->id()])))
                    ->saveQuietly());
        }
    }
}
