<?php

namespace Kraenkvisuell\StatamicReps\Fieldtypes;

use Statamic\Fieldtypes\Text;

/**
 * The title or slug of another site in the default site's form ("Title (English)"): read from the
 * localization, written back by Translations\Listener. Never stored on the origin. Config: `key`
 * (title|slug), `site`.
 */
class SiteValue extends Text
{
    protected static $handle = 'reps_site_value';

    protected $selectable = false;

    protected $component = 'text';

    public function preProcess($data)
    {
        $parent = $this->field?->parent();

        if (! is_object($parent) || ! method_exists($parent, 'hasOrigin') || $parent->hasOrigin()) {
            return $data;
        }

        $localization = Translatable::localization($parent, (string) $this->config('site'));

        if (! $localization) {
            return null;
        }

        return $this->config('key') === 'slug'
            ? ($localization->slug() !== $parent->slug() ? $localization->slug() : null)
            : $localization->data()->get($this->config('key'));
    }

    public function process($data)
    {
        return blank($data) ? null : trim((string) $data);
    }
}
