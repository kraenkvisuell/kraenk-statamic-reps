<?php

namespace Kraenkvisuell\StatamicReps\Translations;

use Illuminate\Support\Str;
use Kraenkvisuell\StatamicReps\Fieldtypes\Translatable;
use Statamic\Events\EntrySaving;
use Statamic\Events\GlobalVariablesSaved;
use Statamic\Events\GlobalVariablesSaving;
use Statamic\Facades\Site;
use Statamic\Globals\Variables;

/**
 * Writes the other sites' values of a default site's form (Translations\Translations) to the
 * localizations. Saving an origin entry saves its localizations too (also the ones Statamic
 * creates for a new entry), so the values wait until each localization is saved. Global
 * variables don't save their localizations: they're saved here after the origin.
 *
 * Only form saves carry these values ([site => value] maps, the `*_<site>` title/slug fields);
 * the import writes plain values and localizations itself and passes untouched.
 */
class Listener
{
    /** @var array<string, array<string, array<string, mixed>>> origin id => site => key => value */
    protected static array $pending = [];

    public function entrySaving(EntrySaving $event): void
    {
        $this->saving($event->entry);
    }

    public function globalsSaving(GlobalVariablesSaving $event): void
    {
        $this->saving($event->variables);
    }

    public function globalsSaved(GlobalVariablesSaved $event): void
    {
        $variables = $event->variables;

        if ($variables->hasOrigin() || ! isset(static::$pending[$this->key($variables)])) {
            return;
        }

        foreach (Translations::otherSites() as $site) {
            Translatable::localization($variables, $site)?->save();
        }
    }

    protected function saving($item): void
    {
        if (! Translations::enabled()) {
            return;
        }

        $item->hasOrigin() ? $this->apply($item) : $this->extract($item);
    }

    /** Origin: keeps its own site's value, puts the others aside for the localizations. */
    protected function extract($origin): void
    {
        $default = Site::default()->handle();
        $pending = [];

        foreach ($origin->blueprint()?->fields()->all() ?? [] as $handle => $field) {
            $type = $field->type();
            $data = $origin->data();

            if ($type === 'reps_translatable' && is_array($value = $data->get($handle))) {
                $sites = array_intersect_key($value, array_flip(Site::all()->map->handle()->all()));

                // a map without site keys is an emptied field (or a list value of a single site)
                if ($sites || $value === []) {
                    $own = $sites[$default] ?? null;
                    blank($own) ? $origin->remove($handle) : $origin->set($handle, $own);

                    foreach (Translations::otherSites() as $site) {
                        $pending[$site][$handle] = $sites[$site] ?? null;
                    }
                }
            }

            if ($type === 'reps_site_value' && $data->has($handle)) {
                $pending[$field->get('site')]['@'.$field->get('key')] = $data->get($handle);
                $origin->remove($handle);
            }
        }

        if ($pending) {
            // a new entry gets its id only when it's written; its localizations need it now
            if (! ($origin instanceof Variables) && ! $origin->id()) {
                $origin->id((string) Str::uuid());
            }

            static::$pending[$this->key($origin)] = $pending;
        }
    }

    /** Localization: takes its values; emptied ones fall back to the origin. */
    protected function apply($localization): void
    {
        $origin = $localization->origin();
        $key = $this->key($origin);
        $values = static::$pending[$key][$localization->locale()] ?? null;

        if ($values === null) {
            return;
        }

        unset(static::$pending[$key][$localization->locale()]);

        foreach ($values as $handle => $value) {
            if ($handle === '@slug') {
                $localization->slug(blank($value) ? $origin->slug() : $value);

                continue;
            }

            $handle = ltrim($handle, '@');

            // an emptied field falls back to the origin; a filled one is kept as entered (the import
            // decides itself which values equal the default language)
            if (blank($value)) {
                $localization->remove($handle);
            } else {
                $localization->set($handle, $value);
            }
        }
    }

    protected function key($item): string
    {
        return $item instanceof Variables ? 'globals:'.$item->handle() : (string) $item->id();
    }
}
