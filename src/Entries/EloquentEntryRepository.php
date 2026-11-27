<?php

namespace Kraenkvisuell\StatamicReps\Entries;

use Statamic\Eloquent\Entries\EntryRepository;

/**
 * The eloquent driver's entry repository, without a full save per entry when a structure changes.
 *
 * After a tree save (a new news item goes first, an item is deleted or moved) Statamic marks every
 * entry whose position changed, and the driver queues one job per entry that saves it again only
 * to write its `order` column and its `parent`: for ~270 news items ~2,200 jobs (each save
 * re-indexes the search and runs every save listener). Here the order is written straight into
 * the column (only where it differs, `updated_at` untouched, nothing dispatched), and only the
 * entries whose parent really changed are saved through the driver's jobs. The tree save itself
 * still clears the static cache (CollectionTreeSaved).
 */
class EloquentEntryRepository extends EntryRepository
{
    public function updateOrders($collection, $ids = null)
    {
        if (! $collection->hasStructure()) {
            return parent::updateOrders($collection, $ids);
        }

        $this->changed($collection, $ids, fn ($entry, $model) => $model->order === null || (int) $model->order !== $entry->order())
            ->each(fn ($entry) => $entry->model()->newQuery()->toBase()->where('id', $entry->id())->update(['order' => $entry->order()]));
    }

    public function updateParents($collection, $ids = null)
    {
        if (! $collection->hasStructure()) {
            return parent::updateParents($collection, $ids);
        }

        $changed = $this->changed($collection, $ids, fn ($entry, $model) => ((string) ($model->data['parent'] ?? '')) !== (string) $entry->parent()?->id());

        if ($changed->isNotEmpty()) {
            parent::updateParents($collection, $changed->map->id()->all());
        }
    }

    protected function changed($collection, $ids, callable $differs)
    {
        return $collection->queryEntries()
            ->when($ids, fn ($query) => $query->whereIn('id', $ids))
            ->get()
            ->filter(fn ($entry) => ($model = $entry->model()) && $differs($entry, $model))
            ->values();
    }
}
