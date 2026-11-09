<?php

namespace Kraenkvisuell\StatamicReps\Translations;

use Kraenkvisuell\StatamicReps\Reps;
use Statamic\Facades\Site;

/**
 * Multi-site sites (kombinatrotweiss) edit every language in the default site's form instead of
 * switching sites ("Working in"). The data stays where Statamic keeps it – the default site's
 * value on the origin entry, the other languages on their localizations – so routing, URLs,
 * templates, SEO and the import are unchanged. Only the blueprints change:
 *
 * - a localizable field becomes a `reps_translatable` field (daun/statamic-multisite-field): it
 *   reads the other sites' values from the localizations and Translations\Listener writes them
 *   back when the origin is saved,
 * - `title` and `slug` stay plain (Statamic needs them as strings), followed by one
 *   `reps_site_value` field per other site ("Title (English)", "Slug (English)"),
 * - one `multisite_switcher` in the sidebar switches all translatable fields at once.
 */
class Translations
{
    public static function enabled(): bool
    {
        return Site::hasMultiple() && Reps::feature('translatable_fields');
    }

    /** @return list<string> */
    public static function otherSites(): array
    {
        return Site::all()->map->handle()->reject(fn ($site) => $site === Site::default()->handle())->values()->all();
    }

    /** Applies the transformation to a list of blueprint field items (imports already inlined). */
    public static function fields(array $fields): array
    {
        if (! static::enabled()) {
            return $fields;
        }

        $result = [];

        foreach ($fields as $item) {
            $field = $item['field'] ?? null;
            $handle = $item['handle'] ?? null;

            if (! is_array($field) || ! $handle || ! ($field['localizable'] ?? false)) {
                $result[] = $item;

                continue;
            }

            // stays localizable: the eloquent driver stores only localizable fields as a
            // localization's own values (`__localized_fields`), everything else falls back
            if (in_array($handle, ['title', 'slug'], true)) {
                $result[] = ['handle' => $handle, 'field' => $field];

                foreach (static::otherSites() as $site) {
                    $result[] = ['handle' => "{$handle}_{$site}", 'field' => static::siteValue($handle, $site, $field)];
                }

                continue;
            }

            $result[] = ['handle' => $handle, 'field' => static::translatable($field)];
        }

        return $result;
    }

    protected static function translatable(array $field): array
    {
        $root = ['type' => 'reps_translatable', 'localizable' => true];

        // the header and the layout belong to the wrapper, the input to the wrapped field
        foreach (['display', 'instructions', 'instructions_position', 'width', 'listable', 'if', 'unless', 'visibility', 'reps_feature'] as $key) {
            if (array_key_exists($key, $field)) {
                $root[$key] = $field[$key];
                unset($field[$key]);
            }
        }

        $field['localizable'] = false;
        $root['field'] = $field;

        return $root;
    }

    protected static function siteValue(string $key, string $site, array $field): array
    {
        $name = Site::get($site)?->name() ?? $site;

        return [
            'type' => 'reps_site_value',
            'key' => $key,
            'site' => $site,
            // translated in the CP like every display (lang/de.json: "Title (English)" …)
            'display' => ($key === 'slug' ? 'Slug' : ($field['display'] ?? 'Title')).' ('.$name.')',
            'instructions' => 'Empty: like the default language.',
            'localizable' => false,
            'listable' => false,
            'width' => $field['width'] ?? 100,
        ];
    }

    /** A switcher for all translatable fields of the form. */
    public static function switcher(string $tab = 'sidebar'): array
    {
        return ['handle' => $tab === 'sidebar' ? 'reps_language' : "reps_language_$tab", 'field' => ['type' => 'multisite_switcher', 'display' => 'Language', 'layout' => 'buttons', 'localizable' => false]];
    }
}
