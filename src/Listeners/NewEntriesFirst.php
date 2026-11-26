<?php

namespace Kraenkvisuell\StatamicReps\Listeners;

use Kraenkvisuell\StatamicReps\Cp\PositionColumn;
use Kraenkvisuell\StatamicReps\Reps;
use Statamic\Events\EntryCreated;
use Statamic\Facades\Blink;

/**
 * Entries created in the collections of `cp.new_entries_first` (orderable) start at position 1
 * instead of Statamic's last place, e.g. news: the newest item opens the grid. Quiet saves (the
 * import, re-syncs) keep their order.
 */
class NewEntriesFirst
{
    public function handle(EntryCreated $event): void
    {
        $entry = $event->entry;
        $collection = $entry->collection();

        if (! in_array($collection->handle(), (array) Reps::config('cp.new_entries_first', []), true) || ! $collection->orderable()) {
            return;
        }

        $tree = $collection->structure()->in($entry->locale());
        $items = collect($tree->tree())->reject(fn ($item) => ($item['entry'] ?? null) === $entry->id())->values()->all();

        $tree->tree([['entry' => $entry->id()], ...$items])->save();
        Blink::forget(PositionColumn::blinkKey($collection, $entry->locale()));
    }
}
