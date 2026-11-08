<?php

namespace Kraenkvisuell\StatamicReps\Seo;

use Illuminate\Support\Collection;
use Kraenkvisuell\StatamicReps\Reps;
use Statamic\Contracts\Assets\Asset;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\Asset as Assets;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Site;
use Statamic\Fields\Value;
use Statamic\Statamic;

/**
 * Head metadata of a page: from the entry's SEO fields, the `seo` global (home page and listing
 * pages) and the og:image fallbacks of the config. Replaces the SEO tab of the old Nova sites.
 */
class Meta
{
    public function __construct(protected ?Entry $entry, protected ?string $index, protected ?string $title, protected string $url) {}

    public function toArray(): array
    {
        $site = Site::current();
        $settings = GlobalSet::findByHandle('site_settings')?->in($site->handle());
        $seo = GlobalSet::findByHandle('seo')?->in($site->handle());
        $siteName = (string) ($settings?->get('website_title') ?: $site->name());
        $get = fn (string $key) => $this->entry
            ? $this->entry->value($key)
            : $seo?->get(($this->index ?? 'home').'_'.$key);
        $home = $seo ? fn (string $key) => $seo->get("home_$key") : fn () => null;

        $indexLabel = $this->index ? Reps::config("seo.index_pages.{$this->index}") : null;
        $indexLabel = is_array($indexLabel) ? ($indexLabel[0] ?? null) : $indexLabel;
        $pageTitle = $get('browser_title') ?: ($this->entry?->value('title') ?? $this->title ?? $indexLabel);
        $isHome = ! $this->entry && ! $this->index && ! $this->title;
        $pageTitle = $pageTitle instanceof Value ? (string) $pageTitle->value() : $pageTitle;

        // an explicit browser title is used as it is, otherwise the title goes into the format
        $title = $get('browser_title') && Reps::config('seo.browser_title_verbatim', true)
            ? $get('browser_title')
            : null;

        $title ??= $pageTitle && ! ($isHome && ! $get('browser_title'))
            ? strtr(Reps::config('seo.title_format'), ['{title}' => $pageTitle, '{site}' => $siteName])
            : strtr(Reps::config('seo.home_title_format'), ['{site}' => $siteName]);

        $description = $get('meta_description') ?: $home('meta_description');
        $robots = (array) ($get('robots') ?? []);

        return [
            'title' => $title,
            'description' => $description,
            'keywords' => $get('meta_keywords') ?: $home('meta_keywords'),
            'robots' => implode(', ', [in_array('noindex', $robots) ? 'noindex' : 'index', in_array('nofollow', $robots) ? 'nofollow' : 'follow']),
            'og_title' => $get('og_title') ?: ($get('browser_title') ? $title : ($pageTitle ?: $title)),
            'og_description' => $get('og_description') ?: $description,
            'og_image' => $this->ogImage($get, $home),
            'og_type' => $this->entry && ! in_array($this->entry->collectionHandle(), ['pages'], true) ? 'article' : 'website',
            'canonical' => Reps::config('seo.canonical') ? $this->url : null,
            'alternates' => Reps::config('seo.hreflang') ? $this->alternates() : [],
            'site_name' => $siteName,
            'locale' => $site->locale(),
        ];
    }

    protected function ogImage(callable $get, callable $home): ?string
    {
        $asset = $this->entry ? $this->entryImage($this->entry) : $this->asset($get('og_image'));
        $asset ??= $this->asset($home('og_image'));
        $asset ??= Reps::config('seo.default_og_image') ? Assets::find(Reps::config('seo.default_og_image')) : null;

        if (! $asset) {
            return null;
        }

        if (! $asset->isImage() || $asset->extensionIsOneOf(['svg'])) {
            return $asset->isImage() ? $asset->absoluteUrl() : null;
        }

        return (string) Statamic::tag('glide')->params(['src' => $asset, 'width' => 1200, 'height' => 630, 'fit' => 'crop_focal', 'absolute' => true])->fetch();
    }

    protected function entryImage(Entry $entry): ?Asset
    {
        foreach (Reps::config('seo.og_image_fallbacks.'.$entry->collectionHandle(), ['og_image']) as $source) {
            $asset = match (true) {
                $source === 'works:first' => $this->firstWorkImage($entry),
                str_contains($source, '.') => $this->relatedImage($entry, $source),
                default => $this->asset($entry->value($source)),
            };

            if ($asset && $asset->isImage()) {
                return $asset;
            }
        }

        return null;
    }

    protected function firstWorkImage(Entry $entry): ?Asset
    {
        foreach ((array) $entry->get('works', []) as $work) {
            if (($work['type'] ?? null) !== 'textbox' && ! empty($work['file']) && $asset = $this->asset($work['file'])) {
                if ($asset->isImage()) {
                    return $asset;
                }
            }
        }

        return null;
    }

    protected function relatedImage(Entry $entry, string $source): ?Asset
    {
        [$relation, $field] = explode('.', $source, 2);
        $related = $entry->value($relation);
        $related = $related instanceof Collection ? $related->first() : $related;

        return $related instanceof Entry ? $this->entryImage($related) ?? $this->asset($related->value($field)) : null;
    }

    protected function asset(mixed $value): ?Asset
    {
        if ($value instanceof Collection) {
            $value = $value->first();
        }

        if ($value instanceof Asset) {
            return $value;
        }

        if (is_array($value)) {
            $value = $value[0] ?? null;
        }

        return is_string($value) && $value !== ''
            ? Assets::find(str_contains($value, '::') ? $value : Reps::config('assets.container').'::'.$value)
            : null;
    }

    /** @return list<array{hreflang: string, url: string}> */
    protected function alternates(): array
    {
        if (Site::all()->count() < 2) {
            return [];
        }

        if (! $this->entry) {
            return Site::all()->map(fn ($site) => ['hreflang' => $site->lang(), 'url' => $site->absoluteUrl().($this->index ? $this->indexPath($site->handle()) : '')])->values()->all();
        }

        $origin = $this->entry->root();

        return Site::all()
            ->map(fn ($site) => ($localized = $origin->in($site->handle())) && $localized->published() && $localized->url()
                ? ['hreflang' => $site->lang(), 'url' => $localized->absoluteUrl()]
                : null)
            ->filter()->values()->all();
    }

    protected function indexPath(string $site): string
    {
        $page = Reps::config("seo.index_pages.$this->index");
        $path = is_array($page) ? ($page[1] ?? '') : '';
        $path = is_array($path) ? ($path[$site] ?? reset($path)) : $path;

        return '/'.ltrim((string) $path, '/');
    }
}
