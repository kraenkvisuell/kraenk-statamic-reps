<?php

namespace Kraenkvisuell\StatamicReps\Listeners;

use Kraenkvisuell\StatamicReps\Reps;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Events\EntrySaved;
use Statamic\Facades\Entry;

/**
 * Keeps both sides of the parent → children relations editors maintain on the parent: an
 * artist's `slideshows` and each slideshow's `artist`; a special category's `projects` and each
 * project's `special_category`. Saving the parent sets the children's parent field; saving a new
 * child (created with "Add" in the parent, which pre-fills the parent field, see
 * Fieldtypes\Slideshows) adds it to the parent's list, so it's there even when the parent form
 * isn't saved afterwards. With `artist_categories_order`, the artist's categories follow its
 * slideshows (new ones appended, the editor's order kept).
 *
 * `complete`: the parent's list always holds every child that points at it, and only those
 * (a slideshow given another artist leaves the old one's list). The artist's
 * slideshows field has no unlink for existing slideshows (resources/js/cp.js), so a slideshow
 * missing from the list was dropped by a form opened before it was created; saving that form
 * (or the slideshow) puts it back. Special categories can unlink, so only new projects are added.
 */
class SyncRelations
{
    public const RELATIONS = [
        'artists' => ['field' => 'slideshows', 'children' => 'slideshows', 'parent' => 'artist', 'complete' => true],
        'special_categories' => ['field' => 'projects', 'children' => 'special_projects', 'parent' => 'special_category', 'complete' => false],
    ];

    // the importer sets both sides itself
    public static bool $enabled = true;

    public function handle(EntrySaved $event): void
    {
        $entry = $event->entry;

        if (! static::$enabled || ! $entry->isRoot()) {
            return;
        }

        $collection = $entry->collectionHandle();

        if (isset(static::RELATIONS[$collection])) {
            $this->syncChildren($entry, static::RELATIONS[$collection]);
        } elseif ($relation = static::relationOfChild($collection)) {
            $this->syncParent($entry, $relation);
        }
    }

    /** The relation a collection is the children's side of. */
    public static function relationOfChild(string $collection): ?array
    {
        return collect(static::RELATIONS)->first(fn ($relation) => $relation['children'] === $collection);
    }

    protected function syncChildren(EntryContract $parent, array $relation): void
    {
        $ids = (array) $parent->get($relation['field'], []);

        foreach ($ids as $childId) {
            $child = Entry::find($childId);

            // also re-saved when the parent's slug changed, so the child's URI follows
            if ($child && ($child->get($relation['parent']) !== $parent->id() || ! str_contains((string) $child->uri(), '/'.$parent->slug().'/'))) {
                $child->set($relation['parent'], $parent->id())->saveQuietly();
            }
        }

        if ($relation['complete']) {
            $missing = Entry::query()
                ->where('collection', $relation['children'])
                ->where($relation['parent'], $parent->id())
                ->get()
                ->filter(fn ($child) => $child->isRoot() && ! in_array($child->id(), $ids, true))
                ->map->id()->values()->all();

            if ($missing) {
                $parent->set($relation['field'], [...$ids, ...$missing])->saveQuietly();
            }
        }

        $this->parentChanged($parent);
    }

    protected function syncParent(EntryContract $child, array $relation): void
    {
        $parentId = collect((array) $child->get($relation['parent']))->first();

        // moved to another artist (the select outside the artist): off the old one's list
        $oldId = collect((array) $child->getOriginal($relation['parent']))->first();

        if ($relation['complete'] && $oldId && $oldId !== $parentId && $old = Entry::find($oldId)) {
            $old->set($relation['field'], array_values(array_diff((array) $old->get($relation['field'], []), [$child->id()])))->saveQuietly();
            $this->parentChanged($old);
        }

        // new: never saved before this save (the original values are only set after it)
        if (! $relation['complete'] && $child->getOriginal() !== []) {
            return;
        }

        $parent = $parentId ? Entry::find($parentId) : null;

        if (! $parent || ! $parent->isRoot() || $parent->collectionHandle() !== array_search($relation, static::RELATIONS, true)) {
            return;
        }

        $ids = (array) $parent->get($relation['field'], []);

        if (! in_array($child->id(), $ids, true)) {
            $parent->set($relation['field'], [...$ids, $child->id()])->saveQuietly();
            $this->parentChanged($parent);
        }
    }

    protected function parentChanged(EntryContract $parent): void
    {
        if ($parent->collectionHandle() === 'artists' && Reps::feature('artist_categories_order')) {
            $this->syncArtistCategories($parent);
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
