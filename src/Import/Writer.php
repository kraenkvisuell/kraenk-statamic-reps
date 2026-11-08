<?php

namespace Kraenkvisuell\StatamicReps\Import;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Ramsey\Uuid\Uuid;
use Statamic\Facades\Collection as Collections;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;

/**
 * Writes imported entries: finds them by `legacy_id`, sets only the fields that differ and reports
 * every change, so a second run with unchanged legacy data saves nothing. Entries created in
 * Statamic (without a legacy_id) are never touched.
 */
class Writer
{
    /** @var array<string, array<int, \Statamic\Entries\Entry>> collection => legacy id => origin entry */
    protected array $entries = [];

    /** @var array<string, array<int, string>> ids reserved for entries that don't exist yet */
    protected array $reserved = [];

    public array $stats = [];

    /** @var list<string> ids of the entries created or changed in this run (for warming their pages) */
    public array $touched = [];

    /** @var array<string, array<int, true>> legacy ids that exist per collection (references to others are dropped) */
    public array $known = [];

    public function __construct(protected Command $output, protected bool $dryRun) {}

    protected function load(string $collection): array
    {
        return $this->entries[$collection] ??= Entry::query()
            ->where('collection', $collection)
            ->where('site', Site::default()->handle())
            ->get()
            ->filter(fn ($entry) => $entry->get('legacy_id'))
            ->keyBy(fn ($entry) => (int) $entry->get('legacy_id'))
            ->all();
    }

    /** The entry id for a legacy row, reserved in advance for rows imported later in the run. */
    public function id(string $collection, int|string|null $legacyId): ?string
    {
        if (! $legacyId) {
            return null;
        }

        $legacyId = (int) $legacyId;

        if (isset($this->known[$collection]) && ! isset($this->known[$collection][$legacyId])) {
            return null;
        }

        // deterministic, so references to rows imported later in the run stay the same between runs
        return ($this->load($collection)[$legacyId] ?? null)?->id()
            ?? ($this->reserved[$collection][$legacyId] ??= Uuid::uuid5(Uuid::NAMESPACE_URL, "reps:$collection:$legacyId")->toString());
    }

    public function exists(string $collection, int|string|null $legacyId): bool
    {
        return $legacyId && isset($this->load($collection)[(int) $legacyId]);
    }

    /** @return list<string> */
    public function ids(string $collection, iterable $legacyIds): array
    {
        return collect($legacyIds)->map(fn ($id) => $this->id($collection, $id))->filter()->values()->all();
    }

    /**
     * @param  array  $data  values of the default site (non-localizable fields included)
     * @param  array  $localized  [site => values] of the localizable fields in the other sites
     * @param  string|array  $slug  one slug or [site => slug]
     */
    public function upsert(string $collection, int $legacyId, string $label, array $data, array $localized = [], string|array|null $slug = null, bool $published = true, mixed $date = null): string
    {
        $default = Site::default()->handle();
        $slugs = is_array($slug) ? $slug : [$default => $slug];
        $entry = $this->load($collection)[$legacyId] ?? null;
        $isNew = ! $entry;

        $this->ensureTree($collection, $default);

        if ($isNew) {
            $entry = Entry::make()->collection($collection)->locale($default)->id($this->id($collection, $legacyId));
        }

        $changes = $this->apply($entry, ['legacy_id' => $legacyId] + $data);

        if ($slugs[$default] && $entry->slug() !== $slugs[$default]) {
            $entry->slug($slugs[$default]);
            $changes[] = 'slug';
        }

        if ($entry->published() !== $published) {
            $entry->published($published);
            $changes[] = 'published';
        }

        if ($date && Collections::findByHandle($collection)?->dated()) {
            $date = Carbon::parse($date)->startOfMinute();
            if (! $entry->hasDate() || ! $entry->date()->eq($date)) {
                $entry->date($date);
                $changes[] = 'date';
            }
        }

        $this->report($collection, $label, $isNew, $changes);

        if (($isNew || $changes) && ! $this->dryRun) {
            $entry->saveQuietly();
            $this->entries[$collection][$legacyId] = $entry;
            $this->touched[] = $entry->id();
        }

        // every site of the collection gets a localization, also when nothing is translated
        foreach (Collections::findByHandle($collection)->sites() as $site) {
            if ($site !== $default) {
                $this->localize($entry, $collection, $label, $site, $localized[$site] ?? [], $slugs[$site] ?? null, $published);
            }
        }

        return $entry->id();
    }

    protected function localize($origin, string $collection, string $label, string $site, array $values, ?string $slug, bool $published): void
    {
        $this->ensureTree($collection, $site);

        $localization = $origin->in($site);
        $isNew = ! $localization;

        // only what differs from the origin is stored; the rest falls back to it
        foreach ($values as $key => $value) {
            if (static::same($value, $origin->get($key))) {
                $values[$key] = null;
            }
        }

        $localization ??= $origin->makeLocalization($site);
        $changes = $this->apply($localization, $values);

        if ($slug && $localization->slug() !== $slug) {
            $localization->slug($slug);
            $changes[] = 'slug';
        }

        if ($localization->published() !== $published) {
            $localization->published($published);
            $changes[] = 'published';
        }

        $this->report($collection, "$label [$site]", $isNew, $changes);

        if (($isNew || $changes) && ! $this->dryRun) {
            $localization->saveQuietly();
        }
    }

    // saving an entry of a structured collection needs the site's tree (the eloquent driver has no row yet)
    protected function ensureTree(string $collection, string $site): void
    {
        $structure = Collections::findByHandle($collection)?->structure();

        if ($structure && ! $structure->in($site) && ! $this->dryRun) {
            $structure->makeTree($site)->save();
        }
    }

    /** Sets changed values, removes blank ones; returns the changed keys. */
    protected function apply($entry, array $data): array
    {
        $changes = [];

        foreach ($data as $key => $value) {
            // false is kept: toggles that default to on (show_title …) must stay off
            $blank = $value === null || $value === '' || $value === [];
            $current = $entry->data()->get($key);

            if ($blank) {
                if ($entry->data()->has($key)) {
                    $entry->remove($key);
                    $changes[] = $key;
                }

                continue;
            }

            if (! static::same($current, $value)) {
                $entry->set($key, $value);
                $changes[] = $key;
            }
        }

        return $changes;
    }

    /** Equal regardless of object key order (Postgres jsonb sorts keys). */
    public static function same(mixed $a, mixed $b): bool
    {
        return json_encode(static::canonical($a)) === json_encode(static::canonical($b));
    }

    protected static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return is_int($value) || is_float($value) ? (string) $value : $value;
        }

        $value = array_map([static::class, 'canonical'], $value);

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    protected function report(string $collection, string $label, bool $isNew, array $changes): void
    {
        $this->stats[$collection][$isNew ? 'created' : ($changes ? 'updated' : 'unchanged')] = ($this->stats[$collection][$isNew ? 'created' : ($changes ? 'updated' : 'unchanged')] ?? 0) + 1;

        if ($isNew) {
            $this->output->line("  <info>create</info> $collection: $label");
        } elseif ($changes) {
            $this->output->line("  <comment>update</comment> $collection: $label (".implode(', ', array_unique($changes)).')');
        }
    }

    /** Deletes imported entries whose legacy row no longer exists. */
    public function prune(string $collection, array $legacyIds): void
    {
        $keep = array_flip(array_map('intval', $legacyIds));

        foreach ($this->load($collection) as $legacyId => $entry) {
            if (isset($keep[$legacyId])) {
                continue;
            }

            $this->output->line("  <fg=red>delete</> $collection: ".($entry->get('title') ?? $legacyId));
            $this->stats[$collection]['deleted'] = ($this->stats[$collection]['deleted'] ?? 0) + 1;

            if (! $this->dryRun) {
                $entry->deleteQuietly();
                unset($this->entries[$collection][$legacyId]);
            }
        }
    }

    /**
     * Orders a structured collection: the given entries first in this order, then the others
     * (created in Statamic) in their current order. Saved only when the order changed.
     */
    public function tree(string $collection, array $legacyIds, ?string $site = null): void
    {
        $sites = $site ? [$site] : Collections::findByHandle($collection)->sites()->all();
        $structure = Collections::findByHandle($collection)->structure();
        $order = $this->ids($collection, $legacyIds);

        foreach ($sites as $site) {
            $tree = $structure->in($site) ?? $structure->makeTree($site);
            $current = collect($tree->tree())->pluck('entry')->filter()->values()->all();
            // each site's tree holds that site's entries (localizations have their own ids)
            $existing = array_values(array_filter(array_map(
                fn ($id) => Entry::find($id)?->in($site)?->id() ?? ($this->dryRun && ! Entry::find($id) ? $id : null),
                $order,
            )));
            // entries created in Statamic keep their place after the imported ones; ids of other
            // sites (left by earlier imports) are dropped
            $others = array_values(array_filter(array_diff($current, $existing), fn ($id) => Entry::find($id)?->locale() === $site));
            $new = array_values(array_unique(array_merge($existing, $others)));

            // keep nested children (pages) where they are
            if ($new === $current) {
                continue;
            }

            $this->output->line("  <comment>order</comment> $collection [$site]: ".count($new).' entries');

            if (! $this->dryRun) {
                $branches = collect($tree->tree())->keyBy('entry');
                $tree->tree(collect($new)->map(fn ($id) => $branches[$id] ?? ['entry' => $id])->all())->save();
            }
        }
    }
}
