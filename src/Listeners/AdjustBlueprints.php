<?php

namespace Kraenkvisuell\StatamicReps\Listeners;

use Kraenkvisuell\StatamicReps\Reps;
use Kraenkvisuell\StatamicReps\Translations\Translations;
use Statamic\Events\EntryBlueprintFound;
use Statamic\Facades\Fieldset;

/**
 * Fits the addon's blueprints to the site's config: inlines the addon fieldsets, removes fields
 * of switched-off features (`reps_feature: <key>`) and blocks the site doesn't use, applies the
 * labels and appends the site's own fields from a `<blueprint>_extra` fieldset.
 */
class AdjustBlueprints
{
    public function handle(EntryBlueprintFound $event): void
    {
        $blueprint = $event->blueprint;
        $collection = preg_replace('~^collections[./]~', '', (string) $blueprint->namespace());

        if (! Reps::hasCollection($collection) || ($blueprint->contents()['reps_adjusted'] ?? false)) {
            return;
        }

        $blueprint->setContents(static::adjust($blueprint->contents(), $blueprint->handle()));
    }

    public static function adjust(array $contents, string $handle): array
    {
        // the artists' slideshows tab is named like the slideshows ("Projects" on some sites)
        if (isset($contents['tabs']['slideshows'])) {
            $contents['tabs']['slideshows']['display'] = Reps::label('slideshows');
        }

        foreach ($contents['tabs'] ?? [] as $tabHandle => $tab) {
            foreach ($tab['sections'] ?? [] as $i => $section) {
                $contents['tabs'][$tabHandle]['sections'][$i]['fields'] = static::fields($section['fields'] ?? []);
            }

            $contents['tabs'][$tabHandle]['sections'] = array_values(array_filter(
                $contents['tabs'][$tabHandle]['sections'],
                fn ($section) => ! empty($section['fields']),
            ));

            if (empty($contents['tabs'][$tabHandle]['sections'])) {
                unset($contents['tabs'][$tabHandle]);
            }
        }

        if ($extra = Fieldset::find("{$handle}_extra")) {
            $contents['tabs']['site'] = [
                'display' => $extra->title(),
                // inlined when translating, so its localizable fields become translatable too
                'sections' => [['fields' => Translations::enabled() ? $extra->contents()['fields'] ?? [] : [['import' => "{$handle}_extra"]]]],
            ];
        }

        if (Translations::enabled()) {
            $contents = static::translate($contents);
        }

        $contents['reps_adjusted'] = true;

        return $contents;
    }

    /**
     * Multi-site: every language in the default site's form (see Translations\Translations). The
     * language switcher goes into the sidebar, or (global sets have none) on top of every tab with
     * translatable fields; all switchers share the selected language.
     */
    protected static function translate(array $contents): array
    {
        $translatedTabs = [];

        foreach ($contents['tabs'] ?? [] as $tabHandle => $tab) {
            foreach ($tab['sections'] ?? [] as $i => $section) {
                $fields = Translations::fields($section['fields'] ?? []);

                if (collect($fields)->contains(fn ($item) => in_array($item['field']['type'] ?? null, ['reps_translatable', 'reps_site_value'], true))) {
                    $translatedTabs[$tabHandle] = true;
                }

                $contents['tabs'][$tabHandle]['sections'][$i]['fields'] = $fields;
            }
        }

        $tabs = isset($contents['tabs']['sidebar']) && $translatedTabs ? ['sidebar'] : array_keys($translatedTabs);

        foreach ($tabs as $tab) {
            array_unshift($contents['tabs'][$tab]['sections'][0]['fields'], Translations::switcher($tab));
        }

        return $contents;
    }

    /** @param  list<array>  $fields */
    public static function fields(array $fields): array
    {
        $result = [];

        foreach ($fields as $item) {
            if (isset($item['import']) && str_starts_with($item['import'], 'reps::')) {
                $fieldset = Fieldset::find($item['import']);
                $imported = static::fields($fieldset ? $fieldset->contents()['fields'] ?? [] : []);
                $prefix = $item['prefix'] ?? '';

                foreach ($imported as $field) {
                    if ($prefix && isset($field['handle'])) {
                        $field['handle'] = $prefix.$field['handle'];
                    }
                    $result[] = $field;
                }

                continue;
            }

            if (isset($item['field']) && ! static::enabled($item['field'])) {
                continue;
            }

            if (isset($item['field'])) {
                $item['field'] = static::field($item['handle'] ?? '', $item['field']);
            }

            $result[] = $item;
        }

        return $result;
    }

    protected static function enabled(array $field): bool
    {
        return ! isset($field['reps_feature']) || Reps::feature($field['reps_feature']);
    }

    protected static function field(string $handle, array $field): array
    {
        unset($field['reps_feature']);

        if (isset($field['fields'])) {
            $field['fields'] = static::fields($field['fields']);
        }

        foreach ($field['sets'] ?? [] as $groupHandle => $group) {
            foreach ($group['sets'] ?? [] as $setHandle => $set) {
                if ($handle === 'main_content' && ! in_array($setHandle, Reps::config('page_blocks', []), true)) {
                    unset($field['sets'][$groupHandle]['sets'][$setHandle]);

                    continue;
                }

                if ($handle === 'works' && $setHandle === 'textbox' && ! Reps::feature('textboxes')) {
                    unset($field['sets'][$groupHandle]['sets'][$setHandle]);

                    continue;
                }

                $field['sets'][$groupHandle]['sets'][$setHandle]['fields'] = static::fields($set['fields'] ?? []);
            }
        }

        if (in_array($handle, ['slideshows', 'works', 'categories', 'disciplines'], true) && isset($field['display'])) {
            $field['display'] = Reps::label($handle);
        }

        $labels = [
            'is_portfolio_image' => 'portfolio_image', 'reps_portfolio_images' => 'portfolio_images',
            'is_startpage_image' => 'startpage_image', 'reps_startpage_images' => 'startpage_images',
        ];

        if (isset($labels[$handle])) {
            $field['display'] = Reps::label($labels[$handle]);
        }

        return $field;
    }
}
