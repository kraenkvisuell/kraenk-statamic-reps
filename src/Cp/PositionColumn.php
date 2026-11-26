<?php

namespace Kraenkvisuell\StatamicReps\Cp;

use Kraenkvisuell\StatamicReps\Reps;
use Statamic\Contracts\Entries\Collection as CollectionContract;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\Blink;
use Statamic\Facades\Collection;
use Statamic\Statamic;

/**
 * A position select as the first column of an orderable collection's CP listing (collections in
 * `cp.position_columns`): the entry's place in the collection's order, changing it moves the entry
 * there right away (Http\Controllers\Cp\PositionController) and the listing reloads (cp.js). The
 * order is saved like a drag in the CP (the tree's save), so static caching invalidates as usual.
 */
class PositionColumn
{
    public const HANDLE = 'reps_position';

    /** @return list<string> */
    public static function collections(): array
    {
        return array_values(array_filter((array) Reps::config('cp.position_columns', []),
            fn ($handle) => Collection::find($handle)?->hasStructure()));
    }

    public static function register(): void
    {
        foreach ((array) Reps::config('cp.position_columns', []) as $handle) {
            Collection::computed($handle, static::HANDLE, fn (Entry $entry) => Statamic::isCpRoute() ? static::value($entry) : null);
        }
    }

    /** The listing column, first in the blueprint, never shown in the form. */
    public static function field(): array
    {
        return ['handle' => static::HANDLE, 'field' => [
            'type' => 'reps_position',
            'display' => 'Position',
            'visibility' => 'computed',
            'listable' => true,
            'sortable' => false,
            'if' => ['title' => 'equals __listing_only__'],
        ]];
    }

    public static function value(Entry $entry): ?array
    {
        $ids = static::ids($entry->collection(), $entry->locale());
        $index = array_search($entry->id(), $ids, true);

        return $index === false ? null : [
            'position' => $index + 1,
            'total' => count($ids),
            'url' => cp_route('reps.position.update', $entry->id()),
        ];
    }

    /**
     * Moves the entry to a 1-based position among the top-level entries and saves the tree.
     *
     * @return bool whether the order changed
     */
    public static function move(Entry $entry, int $position): bool
    {
        $tree = $entry->collection()->structure()->in($entry->locale());
        $items = $tree->tree();
        $index = collect($items)->search(fn ($item) => ($item['entry'] ?? null) === $entry->id());

        if ($index === false) {
            return false;
        }

        $target = max(0, min(count($items) - 1, $position - 1));

        if ($target === $index) {
            return false;
        }

        $item = array_splice($items, $index, 1)[0];
        array_splice($items, $target, 0, [$item]);
        $tree->tree($items)->save();
        Blink::forget(static::blinkKey($entry->collection(), $entry->locale()));

        return true;
    }

    /** @return list<string> ids of the top-level entries in order */
    protected static function ids(CollectionContract $collection, string $site): array
    {
        return Blink::once(static::blinkKey($collection, $site), fn () => collect($collection->structure()?->in($site)?->tree() ?? [])
            ->pluck('entry')->filter()->values()->all());
    }

    public static function blinkKey(CollectionContract $collection, string $site): string
    {
        return "reps-positions-{$collection->handle()}-$site";
    }
}
