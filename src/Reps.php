<?php

namespace Kraenkvisuell\StatamicReps;

class Reps
{
    // collections every site has
    public const CORE_COLLECTIONS = ['pages', 'artists', 'slideshows', 'categories', 'disciplines'];

    // optional collections and the collections they bring along
    public const OPTIONAL_COLLECTIONS = [
        'news' => ['news'],
        'productions' => ['productions'],
        'special_categories' => ['special_categories', 'special_projects'],
        'projects' => ['projects', 'skills'],
        'clients' => ['clients'],
    ];

    public static function config(string $key, mixed $default = null): mixed
    {
        return config("kraenk-statamic-reps.$key", $default);
    }

    public static function feature(string $key): bool
    {
        return (bool) static::config("features.$key", false);
    }

    /** @return list<string> handles of the collections this site uses */
    public static function collections(): array
    {
        $handles = static::CORE_COLLECTIONS;

        foreach (static::OPTIONAL_COLLECTIONS as $key => $collections) {
            if (static::config("collections.$key")) {
                array_push($handles, ...$collections);
            }
        }

        return $handles;
    }

    public static function hasCollection(string $handle): bool
    {
        return in_array($handle, static::collections(), true);
    }

    public static function label(string $key): string
    {
        return (string) static::config("labels.$key", ucfirst($key));
    }

    /**
     * Merges a site's config over the defaults: associative arrays recursively, lists (and
     * scalars) are replaced as a whole, so ['page_blocks' => ['text']] means only text.
     */
    public static function mergeConfig(array $defaults, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            $defaults[$key] = is_array($value) && is_array($defaults[$key] ?? null)
                && ! array_is_list($value) && ! array_is_list($defaults[$key])
                ? static::mergeConfig($defaults[$key], $value)
                : $value;
        }

        return $defaults;
    }
}
