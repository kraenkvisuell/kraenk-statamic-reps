<?php

namespace Kraenkvisuell\StatamicReps\Import;

use Illuminate\Database\Connection;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Kraenkvisuell\StatamicReps\Reps;

/**
 * Read access to the dump of the old Nova site (the `legacy` connection).
 */
class Legacy
{
    protected array $cache = [];

    public function db(): Connection
    {
        return DB::connection('legacy');
    }

    public function hasTable(string $table): bool
    {
        return $this->cache["has:$table"] ??= Schema::connection('legacy')->hasTable($table);
    }

    public function hasColumn(string $table, string $column): bool
    {
        return in_array($column, $this->cache["columns:$table"] ??= Schema::connection('legacy')->getColumnListing($table), true);
    }

    /** Rows of a portfolio table (artists, slideshows …), with the site's table prefix. */
    public function portfolio(string $table): Collection
    {
        return $this->rows(Reps::config('legacy.portfolio_prefix').$table);
    }

    public function portfolioTable(string $table): string
    {
        return Reps::config('legacy.portfolio_prefix').$table;
    }

    public function rows(string $table): Collection
    {
        if (! $this->hasTable($table)) {
            return collect();
        }

        return $this->cache["rows:$table"] ??= $this->db()->table($table)
            ->when($this->hasColumn($table, 'sort_order'), fn ($q) => $q->orderBy('sort_order'))
            ->orderBy($this->hasColumn($table, 'id') ? 'id' : $this->db()->raw('1'))
            ->get()
            ->keyBy(fn ($row) => $row->id ?? spl_object_id($row));
    }

    /** @return array<string, string> old locale => Statamic site handle */
    public function locales(): array
    {
        return Reps::config('legacy.locales', ['en' => 'default']);
    }

    public function defaultLocale(): string
    {
        return array_key_first($this->locales());
    }

    /**
     * A translatable value in one locale (spatie-translatable JSON or plain), falling back to the
     * default locale and then to any locale, like the old site did.
     */
    public function translated(mixed $value, ?string $locale = null): string
    {
        $locale ??= $this->defaultLocale();

        if (is_string($value) && str_starts_with(ltrim($value), '{')) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : $value;
        }

        if (! is_array($value)) {
            return (string) ($value ?? '');
        }

        foreach ([$locale, $this->defaultLocale()] as $key) {
            if (filled($value[$key] ?? null)) {
                return (string) $value[$key];
            }
        }

        return (string) (collect($value)->first(fn ($v) => filled($v)) ?? '');
    }

    /** The value per Statamic site: [site => value]. */
    public function perSite(mixed $value, ?callable $map = null): array
    {
        $result = [];

        foreach ($this->locales() as $locale => $site) {
            $v = $this->translated($value, $locale);
            $result[$site] = $map ? $map($v, $locale) : $v;
        }

        return $result;
    }

    public function json(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        $decoded = is_string($value) ? json_decode($value, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    public function settings(): Collection
    {
        return $this->cache['settings'] ??= $this->hasTable('nova_settings')
            ? $this->db()->table('nova_settings')->pluck('value', 'key')
            : collect();
    }

    /** Keys of a JSON flag map ({"46": true, "51": false}) whose value is truthy. */
    public function trueKeys(mixed $value): array
    {
        return array_keys(array_filter($this->json($value)));
    }

    public function singleLine(mixed $value, ?string $locale = null): string
    {
        return trim(preg_replace('/\s+/u', ' ', $this->translated($value, $locale)));
    }

    public function first(array $array): mixed
    {
        return Arr::first($array);
    }
}
