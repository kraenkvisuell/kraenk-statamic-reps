<?php

namespace Kraenkvisuell\StatamicReps\Http\Controllers;

use Kraenkvisuell\StatamicReps\Reps;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;

/** /sitemap.xml: the home pages, the listing pages of seo.index_pages and every indexable entry. */
class SitemapController
{
    public function __invoke()
    {
        $urls = [];

        foreach (Site::all() as $site) {
            $urls[] = ['loc' => $site->absoluteUrl(), 'lastmod' => null];

            foreach (Reps::config('seo.index_pages', []) as $page) {
                $path = is_array($page) ? ($page[1] ?? null) : null;
                $path = is_array($path) ? ($path[$site->handle()] ?? null) : $path;

                if ($path) {
                    $urls[] = ['loc' => rtrim($site->absoluteUrl(), '/').'/'.ltrim($path, '/'), 'lastmod' => null];
                }
            }
        }

        $collections = array_filter(
            Reps::config('seo.sitemap_collections', []),
            fn ($handle) => Reps::hasCollection($handle) && Collection::findByHandle($handle)?->route(Site::default()->handle()),
        );

        Entry::query()
            ->whereIn('collection', $collections)
            ->where('published', true)
            ->get()
            ->reject(fn ($entry) => in_array('noindex', (array) $entry->get('robots', []), true)
                || $entry->get('is_external')
                || ! $entry->url())
            ->each(function ($entry) use (&$urls) {
                $urls[] = ['loc' => $entry->absoluteUrl(), 'lastmod' => $entry->lastModified()?->toW3cString()];
            });

        return response()
            ->view('kraenk-statamic-reps::sitemap', ['urls' => collect($urls)->unique('loc')->values()])
            ->header('Content-Type', 'text/xml');
    }
}
