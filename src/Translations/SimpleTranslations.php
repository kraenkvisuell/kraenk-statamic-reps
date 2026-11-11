<?php

namespace Kraenkvisuell\StatamicReps\Translations;

use Bastihilger\SimpleStatamicTranslations\Translations as Languages;
use Kraenkvisuell\StatamicReps\Reps;
use Statamic\Facades\Site;
use Statamic\Structures\Page;

/**
 * One site, every language in the same entry (bastihilger/simple-statamic-translations): the
 * blueprints' localizable fields become translated fields, each wrapped on its own under its own
 * handle, so templates read them as before (`{{ description }}` is the current language's).
 * `title` and `slug` stay plain and get a Translation field (`title_translation`,
 * `slug_translation`); `robots` (also `home_robots` …) is the same in every language.
 */
class SimpleTranslations
{
    protected const PLAIN = ['robots'];

    // the header and the layout belong to the wrapper, the input to the wrapped field
    protected const WRAPPER_KEYS = ['width', 'if', 'unless', 'visibility', 'listable', 'reps_feature'];

    public static function enabled(): bool
    {
        return Reps::feature('simple_translations')
            && ! Site::hasMultiple()
            && class_exists(Languages::class);
    }

    /** @param  list<array>  $fields  blueprint field items (imports already inlined) */
    public static function fields(array $fields): array
    {
        $result = [];

        foreach ($fields as $item) {
            $field = $item['field'] ?? null;
            $handle = $item['handle'] ?? null;

            if (! is_array($field) || ! $handle || ! ($field['localizable'] ?? false)) {
                $result[] = $item;

                continue;
            }

            unset($field['localizable']);

            if (in_array($handle, ['title', 'slug'], true)) {
                $result[] = ['handle' => $handle, 'field' => $field];
                $result[] = ['handle' => "{$handle}_translation", 'field' => [
                    'type' => 'translation',
                    'field' => $handle,
                    'display' => $handle === 'slug' ? 'Slug (translation)' : 'Title (translation)',
                    'listable' => false,
                    'width' => $field['width'] ?? 100,
                ]];

                continue;
            }

            if (collect(static::PLAIN)->contains(fn ($plain) => $handle === $plain || str_ends_with($handle, '_'.$plain))) {
                $result[] = ['handle' => $handle, 'field' => $field];

                continue;
            }

            $result[] = ['handle' => $handle, 'field' => static::wrap($handle, $field)];
        }

        return $result;
    }

    /**
     * A field's raw value in the current language (for PHP that reads values instead of
     * augmenting them): translated fields with the fallback, `title`/`slug` through their
     * Translation field. Anything else, and every value without this feature, as it's stored.
     */
    public static function read($item, string $key): mixed
    {
        $value = $item?->value($key);

        if (! $item || ! static::enabled()) {
            return $value;
        }

        $language = Languages::current();
        $default = Languages::default();

        if (in_array($key, ['title', 'slug'], true)) {
            $translated = $language === $default ? null : (Languages::normalize($item->value("{$key}_translation"))[$language] ?? null);

            return filled($translated) ? $translated : ($key === 'slug' && method_exists($item, 'slug') ? $item->slug() : $value);
        }

        if (! is_array($value) || array_is_list($value) || array_diff_key($value, Languages::languages())) {
            return $value;
        }

        $translated = $value[$language][$key] ?? null;

        return blank($translated) && Languages::fallback() ? ($value[$default][$key] ?? null) : $translated;
    }

    /**
     * Absolute URLs of the current page in every language (code => url): an entry's own URL in
     * that language, other pages with the URL's language segment swapped.
     *
     * @return array<string, string>
     */
    public static function urls($entry = null): array
    {
        // a tree page keeps its URL once built: the entry builds it per language
        $entry = $entry instanceof Page ? $entry->entry() : $entry;
        $segments = explode('/', trim(request()->path(), '/'));

        return collect(Languages::codes())->mapWithKeys(function ($code) use ($entry, $segments) {
            if ($entry && Languages::routeHasLanguage($entry->route())) {
                return [$code => Languages::in($code, fn () => $entry->absoluteUrl())];
            }

            $path = Languages::exists($segments[0] ?? null) ? [$code, ...array_slice($segments, 1)] : [$code];

            return [$code => url(implode('/', $path))];
        })->all();
    }

    /** og:locale of the current language: the site's for the default language, else e.g. en_US. */
    public static function locale(): string
    {
        $language = Languages::current();

        return $language === Languages::default()
            ? Site::current()->locale()
            : (Reps::config("seo.locales.$language") ?? $language);
    }

    protected static function wrap(string $handle, array $field): array
    {
        $wrapper = [
            'type' => 'translated',
            'display' => $field['display'] ?? null,
            'hide_display' => true,
            'border' => false,
            'fullscreen' => false,
        ];

        foreach (static::WRAPPER_KEYS as $key) {
            if (array_key_exists($key, $field)) {
                $wrapper[$key] = $field[$key];
                unset($field[$key]);
            }
        }

        $wrapper['fields'] = [['handle' => $handle, 'field' => $field]];

        return array_filter($wrapper, fn ($value) => $value !== null);
    }
}
