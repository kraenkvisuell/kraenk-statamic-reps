<?php

namespace Kraenkvisuell\StatamicReps\Fieldtypes;

use Daun\StatamicMultisiteField\Fieldtypes\Multisite;
use Daun\StatamicMultisiteField\SiteLabels;
use Kraenkvisuell\StatamicReps\Translations\Translations;
use Statamic\Fields\Value;
use Statamic\Globals\Variables;

/**
 * A translatable field of the default site's form (see Translations\Translations): a multisite
 * field whose other sites' values live on the localizations, as with native localizable fields.
 * The form reads them from there; Translations\Listener writes them back on save. Stored on
 * each entry is the plain value of its own site, so templates and code read it as before.
 */
class Translatable extends Multisite
{
    protected static $handle = 'reps_translatable';

    protected $selectable = false;

    protected $component = 'multisite';

    /** The origin entry / global variables edited in the form (null for localizations). */
    protected function origin(): mixed
    {
        $parent = $this->field?->parent();

        if (! is_object($parent) || ! method_exists($parent, 'hasOrigin') || $parent->hasOrigin()) {
            return null;
        }

        return $parent;
    }

    public static function localization(mixed $origin, string $site): mixed
    {
        return $origin instanceof Variables ? $origin->globalSet()->in($site) : $origin->in($site);
    }

    /** [site => value]: the default site's value plus what each localization stores itself. */
    protected function siteValues($value): array
    {
        $origin = $this->origin();

        if (! $origin || ! Translations::enabled() || (is_array($value) && array_intersect_key($value, array_flip($this->siteHandles())))) {
            return $this->normalizeValue($value);
        }

        $values = [$this->defaultSite() => $value];

        foreach (Translations::otherSites() as $site) {
            $values[$site] = static::localization($origin, $site)?->data()->get($this->field->handle());
        }

        return array_filter($values, fn ($v) => ! blank($v));
    }

    public function preProcess($data)
    {
        return parent::preProcess($this->siteValues($data) ?: null);
    }

    // in the default site's form always a [site => value] map (also when emptied), so the
    // listener knows which translations were removed
    public function process($data)
    {
        $values = parent::process($data);

        return $this->origin() && Translations::enabled() ? (array) ($values ?? []) : $values;
    }

    public function preload(): array
    {
        $value = $this->siteValues($this->field?->value());

        return [
            'multisite' => $this->multisite(),
            'sites' => SiteLabels::sites(),
            'defaultSite' => $this->defaultSite(),
            'layout' => $this->layout(),
            'inherit' => $this->inherits(),
            'fallback' => $this->fallbackHandle(),
            'placeholder' => $this->config('placeholder'),
            'inner' => [
                'handle' => static::INNER,
                'config' => $this->childField()->toPublishArray(),
            ],
            'fields' => collect($this->siteHandles())
                ->mapWithKeys(fn ($site) => [$site => $this->siteFields($site, $value[$site] ?? null)->meta()->get(static::INNER)])
                ->all(),
            'new' => $this->fields()->meta()->get(static::INNER),
        ];
    }

    // the stored value is already the entry's own site's (or inherited) value; unwrapped, so an
    // empty field is null for `{{ headline ?? title }}` like the plain fieldtype's
    public function augment($value)
    {
        return $this->unwrap($this->childValue($this->site(), $this->plain($value), 'augment'));
    }

    public function shallowAugment($value)
    {
        return $this->unwrap($this->childValue($this->site(), $this->plain($value), 'shallowAugment'));
    }

    protected function unwrap($value)
    {
        return $value instanceof Value ? $value->value() : $value;
    }

    protected function site(): string
    {
        $parent = $this->field?->parent();

        return is_object($parent) && method_exists($parent, 'locale') ? $parent->locale() : $this->defaultSite();
    }

    // a value saved as [site => value] (if the listener didn't run) still renders
    protected function plain($value)
    {
        if (is_array($value) && array_intersect_key($value, array_flip($this->siteHandles()))) {
            return $value[$this->site()] ?? $value[$this->defaultSite()] ?? null;
        }

        return $value;
    }
}
