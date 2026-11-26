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

        foreach (SyncRelations::RELATIONS as $collection => $relation) {
            Entry::query()
                ->where('collection', $collection)
                ->whereJsonContains($relation['field'], $entry->id())
                ->get()
                ->filter(fn ($parent) => $parent->isRoot())
                ->each(fn ($parent) => $parent
                    ->set($relation['field'], array_values(array_diff((array) $parent->get($relation['field'], []), [$entry->id()])))
                    ->saveQuietly());
        }
    }
}
