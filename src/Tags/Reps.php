<?php

namespace Kraenkvisuell\StatamicReps\Tags;

use Illuminate\Support\Collection;
use Kraenkvisuell\StatamicReps\Lightbox\Lightbox;
use Kraenkvisuell\StatamicReps\Portfolio;
use Kraenkvisuell\StatamicReps\Reps as Config;
use Kraenkvisuell\StatamicReps\Seo\Meta;
use Statamic\Contracts\Entries\Entry;
use Statamic\Fields\Value;
use Statamic\Tags\Tags;

class Reps extends Tags
{
    protected static $handle = 'reps';

    /**
     * {{ reps:seo }} – the head tags. On listing pages pass the key of seo.index_pages:
     * {{ reps:seo index="artists" }}; a page without SEO fields can pass title="…".
     * As a pair it exposes the values ({{ reps:seo }}{{ title }}{{ /reps:seo }}).
     */
    public function seo(): string|array
    {
        $entry = $this->context->get('page');
        $entry = $entry instanceof Entry ? $entry : null;
        $entry = $entry && ! $this->params->get('index') ? $entry : null;

        $meta = (new Meta($entry, $this->params->get('index'), $this->params->get('title'), $entry?->absoluteUrl() ?? url()->current()))->toArray();

        if (! $this->isPair) {
            return view('kraenk-statamic-reps::seo', $meta)->render();
        }

        return $meta;
    }

    /**
     * {{ reps:lightbox }}…{{ /reps:lightbox }} – the visitor's lightbox grouped by artist:
     * {{ artist }}, {{ works }}{{ key }} {{ asset }} {{ work:title }} {{ slideshow:url }}{{ /works }}.
     * {{ reps:lightbox:count }}, {{ reps:lightbox:key slideshow="…" work="…" }}.
     */
    public function lightbox(): array
    {
        return app(Lightbox::class)->grouped()->map(fn ($group) => [
            'artist' => $group['artist'],
            'works' => collect($group['works'])->map(fn ($item) => [
                'key' => $item['key'],
                'asset' => $item['asset'],
                'work' => $item['work'],
                'slideshow' => $item['slideshow'],
                'ratio' => $item['ratio'],
            ])->all(),
        ])->all();
    }

    /**
     * {{ reps:works artist="…" flag="is_startpage_image" limit="1" fallback="true" }} – flagged works
     * of an artist; {{ reps:works slideshow="…" }} – all works of a slideshow. Items: see Portfolio.
     */
    public function works(): array
    {
        if ($slideshow = $this->entry('slideshow')) {
            return Portfolio::items($slideshow)->all();
        }

        $artist = $this->entry('artist');

        return $artist ? Portfolio::flagged(
            $artist,
            (string) $this->params->get('flag', 'show_in_overview'),
            $this->params->int('limit') ?: null,
            $this->params->bool('fallback'),
        )->all() : [];
    }

    /** {{ reps:artist_categories artist="…" limit="99" }}{{ category }} {{ slideshows }}{{ slideshow }} {{ items }}… */
    public function artistCategories(): array
    {
        $artist = $this->entry('artist');

        return $artist ? Portfolio::artistCategories($artist, ! $this->params->bool('all'), $this->params->int('limit') ?: null)->all() : [];
    }

    /** {{ reps:category_artists category="…" fallback="2" discipline="…" }}{{ artist }} {{ items }}… */
    public function categoryArtists(): array
    {
        $category = $this->entry('category');

        return $category ? Portfolio::categoryArtists($category, $this->params->int('fallback'), $this->entry('discipline'))->all() : [];
    }

    /** {{ reps:overview artist="…" category="…" }}{{ slideshow }} {{ items }}… */
    public function overview(): array
    {
        $artist = $this->entry('artist');
        $category = $this->entry('category');

        return $artist ? Portfolio::overview($artist, $category?->id())->all() : [];
    }

    /** {{ reps:slideshows artist="…" visible="true" }} – the artist's published slideshows in order. */
    public function slideshows(): array
    {
        $artist = $this->entry('artist');

        return $artist ? Portfolio::slideshows($artist, $this->params->bool('visible'))->all() : [];
    }

    /** {{ reps:rows :items="items" per_row="4" width="90" }}{{ height }} {{ items }}{{ box_width }}… – justified rows */
    public function rows(): array
    {
        $items = $this->params->get('items', []);
        $items = $items instanceof Value ? $items->value() : $items;
        $items = $items instanceof Collection ? $items->all() : (array) $items;

        return Portfolio::rows($items, max(1, $this->params->int('per_row', 2)), (float) $this->params->get('width', 100));
    }

    protected function entry(string $param): ?Entry
    {
        $value = $this->params->get($param);
        $value = $value instanceof Value ? $value->value() : $value;

        return Portfolio::artist($value);
    }

    /** {{ reps:feature key="lightbox" }} – whether a feature is on. */
    public function feature(): bool
    {
        return Config::feature((string) $this->params->get('key'));
    }

    /** {{ reps:config key="labels.slideshows" }} */
    public function config(): mixed
    {
        return Config::config((string) $this->params->get('key'));
    }
}
