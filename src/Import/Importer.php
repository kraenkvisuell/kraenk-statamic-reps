<?php

namespace Kraenkvisuell\StatamicReps\Import;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Kraenkvisuell\StatamicReps\Listeners\SyncRelations;
use Kraenkvisuell\StatamicReps\Reps;
use Kraenkvisuell\StatamicReps\Translations\SimpleTranslations;
use Statamic\Facades\Entry;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Nav;
use Statamic\Facades\Site;

/**
 * Imports the old Nova site into the reps content model. Nova stays the source until go-live:
 * every run makes the imported entries match the legacy data (and deletes imported entries whose
 * row is gone), but leaves everything created in Statamic alone.
 */
class Importer
{
    public Legacy $legacy;

    public Writer $writer;

    public Media $media;

    public Content $content;

    protected array $only;

    public function __construct(protected Command $output, protected bool $dryRun, array $only = [], protected bool $prune = true)
    {
        $this->legacy = new Legacy;
        $this->writer = new Writer($output, $dryRun);
        $this->media = new Media($this->legacy, $output, $dryRun);
        $this->content = new Content($this->legacy, $this->media, $this->writer, $output);
        $this->only = $only;
    }

    // legacy ids per collection, so references to deleted rows are dropped
    protected function registerKnownIds(): void
    {
        $tables = [
            'disciplines' => $this->legacy->portfolioTable('disciplines'),
            'categories' => $this->legacy->portfolioTable('categories'),
            'artists' => $this->legacy->portfolioTable('artists'),
            'slideshows' => $this->legacy->portfolioTable('slideshows'),
            'pages' => 'pages',
            'news' => $this->legacy->hasTable('posts') ? 'posts' : 'news_items',
            'productions' => 'productions',
            'special_categories' => 'special_categories',
            'special_projects' => 'special_projects',
            'projects' => $this->legacy->portfolioTable('projects'),
            'skills' => $this->legacy->portfolioTable('skills'),
            'clients' => 'logos',
        ];

        foreach ($tables as $collection => $table) {
            if ($this->legacy->hasTable($table)) {
                $this->writer->known[$collection] = array_fill_keys($this->legacy->db()->table($table)->pluck('id')->map(fn ($id) => (int) $id)->all(), true);
            }
        }
    }

    protected function wants(string $step): bool
    {
        return ! $this->only || in_array($step, $this->only, true);
    }

    public function run(bool $withMedia = true): void
    {
        $this->registerKnownIds();
        SyncRelations::$enabled = false;

        $this->mapPageUrls();

        $steps = [
            'disciplines' => fn () => $this->taxonomyLike('disciplines', 'disciplines'),
            'categories' => fn () => $this->taxonomyLike('categories', 'categories'),
            'artists' => fn () => $this->artists(),
            'slideshows' => fn () => $this->slideshows(),
            'pages' => fn () => $this->pages(),
            'news' => fn () => Reps::hasCollection('news') ? $this->news() : null,
            'productions' => fn () => Reps::hasCollection('productions') ? $this->productions() : null,
            'special_categories' => fn () => Reps::hasCollection('special_categories') ? $this->specialCategories() : null,
            'projects' => fn () => Reps::hasCollection('projects') ? $this->projects() : null,
            'clients' => fn () => Reps::hasCollection('clients') ? $this->clients() : null,
            'globals' => fn () => $this->globals(),
            'navigation' => fn () => $this->navigations(),
        ];

        foreach ($steps as $step => $run) {
            if ($this->wants($step)) {
                $this->output->info(Str::headline($step));
                $run();
            }
        }

        if ($withMedia) {
            $this->output->info('Media');
            $this->media->sync();
        }
    }

    /**
     * Splits values into the default site's data and the other sites' localized values.
     *
     * @param  array  $shared  non-localizable values
     * @param  callable  $localizable  fn (string $locale): array
     * @return array{0: array, 1: array}
     */
    protected function sites(array $shared, callable $localizable): array
    {
        $default = Site::default()->handle();
        $data = $shared;
        $localized = [];

        foreach ($this->legacy->locales() as $locale => $site) {
            $values = $localizable($locale);

            if ($site === $default) {
                $data = $values + $data;
            } elseif (SimpleTranslations::enabled()) {
                $localized[$locale] = $values;   // one site: keyed by language (Writer::upsert)
            } elseif (Site::get($site)) {
                $localized[$site] = $values;
            }
        }

        return [$data, $localized];
    }

    /** [site => slug] from a translatable slug column. */
    protected function slugs(mixed $slug, string $fallback): array
    {
        $slugs = [];

        foreach ($this->legacy->locales() as $locale => $site) {
            $key = SimpleTranslations::enabled() && $site !== Site::default()->handle() ? $locale : $site;
            $slugs[$key] = Str::slug($this->legacy->translated($slug, $locale) ?: $fallback) ?: Str::slug($fallback);
        }

        return $slugs;
    }

    protected function title(object $row, string $column = 'title'): string
    {
        return $this->legacy->singleLine($row->{$column} ?? '') ?: '#'.$row->id;
    }

    // Old page URLs (/pages/slug, /visit/slug …) → entry ids, for links inside content
    protected function mapPageUrls(): void
    {
        $folder = trim((string) Reps::config('legacy.page_route', 'pages'), '/');

        foreach ($this->legacy->rows('pages') as $page) {
            foreach (array_keys($this->legacy->locales()) as $locale) {
                $slug = $this->legacy->translated($page->slug, $locale);
                if ($slug) {
                    $id = $this->writer->id('pages', $page->id);
                    $this->content->urls["/$folder/$slug"] = $id;
                    $this->content->urls["/$locale/$folder/$slug"] = $id;
                }
            }
        }
    }

    // ---------------------------------------------------------------- portfolio

    protected function taxonomyLike(string $table, string $collection): void
    {
        $rows = $this->legacy->portfolio($table);
        // the old category pages ordered their slideshows by the pivot's sort_order
        $slideshowOrder = $collection === 'categories' ? $this->pivot('category_slideshow', 'category_id', 'slideshow_id') : collect();

        foreach ($rows as $row) {
            [$data, $localized] = $this->sites(
                array_filter([
                    'main_image' => property_exists($row, 'main_image') ? $this->media->path($row->main_image) : null,
                    'show_in_home_navi' => (bool) ($row->show_in_home_navi ?? false),
                    'show_in_main_menu' => (bool) ($row->show_in_main_menu ?? false),
                    'bgcolor' => $row->bgcolor ?? null,
                    'og_image' => $this->content->ogImage($row),
                    'slideshow_order' => $this->writer->ids('slideshows', $slideshowOrder[$row->id] ?? []) ?: null,
                ], fn ($v) => $v !== null),
                fn ($locale) => [
                    'title' => $this->legacy->singleLine($row->title, $locale),
                    'description' => $this->content->bard($this->legacy->translated($row->description ?? '', $locale)),
                ] + $this->content->seo($row, $locale),
            );

            $this->writer->upsert($collection, $row->id, $this->title($row), $data, $localized,
                $this->slugs($row->slug, $this->title($row)),
                published: ! property_exists($row, 'is_published') || (bool) $row->is_published);
        }

        $this->finish($collection, $rows);
    }

    protected function pivot(string $table, string $key, string $value): Collection
    {
        $table = $this->legacy->portfolioTable($table);

        if (! $this->legacy->hasTable($table)) {
            return collect();
        }

        return $this->legacy->db()->table($table)
            ->when($this->legacy->hasColumn($table, 'sort_order'), fn ($q) => $q->orderBy('sort_order'))
            ->get()
            ->groupBy($key)
            ->map(fn ($rows) => $rows->pluck($value)->unique()->values());
    }

    protected function artists(): void
    {
        $rows = $this->legacy->portfolio('artists');
        $slideshows = $this->legacy->portfolio('slideshows')->sortBy('sort_order')->groupBy('artist_id');
        $disciplines = $this->pivot('artist_discipline', 'artist_id', 'discipline_id');
        $categories = $this->pivot('artist_category', 'artist_id', 'category_id');

        foreach ($rows as $row) {
            [$data, $localized] = $this->sites([
                'title' => trim($row->name),
                'portrait_image' => $this->media->path($row->portrait_image ?? null),
                'portfolio_image' => $this->media->path($row->portfolio_image ?? null),
                'slideshows' => $this->writer->ids('slideshows', ($slideshows[$row->id] ?? collect())->pluck('id')),
                'disciplines' => $this->writer->ids('disciplines', $disciplines[$row->id] ?? []),
                'categories' => Reps::feature('artist_categories_order') ? $this->writer->ids('categories', $categories[$row->id] ?? []) : null,
                'email' => trim((string) ($row->email ?? '')),
                'website' => trim((string) ($row->website ?? '')),
                'social_links' => $this->content->socialLinks($row->social_links ?? null, "artist|$row->id"),
                'is_external' => (bool) ($row->is_external ?? false),
                'bgcolor' => Reps::feature('artist_bgcolor') ? ($row->bgcolor ?? null) : null,
                'sedcard_pdf' => $this->media->path($row->sedcard_pdf ?? null),
                'skill_header_image' => Reps::feature('artist_skills') ? $this->media->path($row->skill_header_image ?? null) : null,
                'skill_image' => Reps::feature('artist_skills') ? $this->media->path($row->skill_image ?? null) : null,
                'og_image' => $this->content->ogImage($row),
            ], fn ($locale) => [
                'description' => $this->content->bard($this->legacy->translated($row->description ?? '', $locale)),
                'testimonials' => Reps::feature('artist_testimonials') ? collect($this->legacy->json($row->testimonials ?? null))->map(fn ($t, $i) => array_filter([
                    'id' => $this->content->rowId('testimonial', (string) $row->id, (string) $i),
                    'text' => trim($this->legacy->translated($t['attributes']['text'] ?? '', $locale)),
                    'client' => $this->legacy->singleLine($t['attributes']['client'] ?? '', $locale),
                ]))->filter(fn ($t) => isset($t['text']))->values()->all() : null,
                'skill_headline' => Reps::feature('artist_skills') ? $this->legacy->singleLine($row->skill_headline ?? '', $locale) : null,
                'skill_text' => Reps::feature('artist_skills') ? $this->content->bard($this->legacy->translated($row->skill_text ?? '', $locale)) : null,
                'skill_description' => Reps::feature('artist_skills') ? $this->skillDescription($row, $locale) : null,
            ] + $this->content->seo($row, $locale));

            $this->writer->upsert('artists', $row->id, $data['title'], $data, $localized,
                Str::slug($row->slug ?: $row->name), published: (bool) $row->is_published);
        }

        $this->finish('artists', $rows);
    }

    protected function skillDescription(object $row, string $locale): array
    {
        return collect($this->legacy->json($row->skill_description ?? null))->map(fn ($block, $i) => array_filter([
            'id' => $this->content->rowId('skill', (string) $row->id, (string) $i),
            'type' => 'text',
            'enabled' => true,
            'headline' => $this->legacy->singleLine($block['attributes']['headline'] ?? '', $locale),
            'text' => $this->content->bard($this->legacy->translated($block['attributes']['text'] ?? '', $locale)),
        ]))->filter(fn ($b) => isset($b['headline']) || isset($b['text']))->values()->all();
    }

    protected function slideshows(): void
    {
        $rows = $this->legacy->portfolio('slideshows');
        $works = $this->legacy->portfolio('works')->sortBy('sort_order')->groupBy('slideshow_id');
        $categories = $this->pivot('category_slideshow', 'slideshow_id', 'category_id');
        $artists = $this->legacy->portfolio('artists');

        foreach ($rows as $row) {
            $artist = $artists[$row->artist_id] ?? null;

            [$data, $localized] = $this->sites([
                'title' => $this->legacy->singleLine($row->title) ?: '#'.$row->id,
                'artist' => $this->writer->id('artists', $row->artist_id),
                'categories' => $this->writer->ids('categories', $categories[$row->id] ?? []),
                'discipline' => Reps::feature('slideshow_disciplines') ? $this->writer->id('disciplines', $row->discipline_id ?? null) : null,
                'works' => ($works[$row->id] ?? collect())->map(fn ($work) => $this->work($work))->values()->all(),
                'is_visible_in_overview' => Reps::feature('overview') ? (bool) ($row->is_visible_in_overview ?? true) : null,
                'show_title' => Reps::feature('show_title') ? (bool) ($row->show_title ?? true) : null,
                'title_position' => Reps::feature('title_position') ? ($row->title_position ?? null) : null,
                'break_after_in_overviews' => Reps::feature('break_after') && ($row->break_after_in_overviews ?? 'none') !== 'none' ? $row->break_after_in_overviews : null,
                'starts_right' => Reps::feature('starts_right') ? (bool) ($row->starts_right ?? false) : null,
                'og_image' => $this->content->ogImage($row),
            ], fn ($locale) => [
                'description' => $this->content->bard($this->legacy->translated($row->description ?? '', $locale)),
            ] + $this->content->seo($row, $locale));

            $label = ($artist ? trim($artist->name).' / ' : '').$data['title'];

            $this->writer->upsert('slideshows', $row->id, $label, $data, $localized,
                Str::slug($row->slug ?: $data['title']) ?: 'slideshow-'.$row->id,
                published: (bool) $row->is_published, date: $row->created_at ?? null);
        }

        $this->finish('slideshows', $rows, tree: false);
    }

    protected function work(object $work): array
    {
        $type = match (true) {
            Reps::feature('textboxes') && (bool) ($work->is_textbox ?? false) => 'textbox',
            filled($work->embed_code ?? null) || filled($work->embed_url ?? null) => 'embed',
            default => 'image',
        };

        $categoryIds = fn ($value) => $this->writer->ids('categories', array_filter(
            array_map(fn ($key) => (int) Str::afterLast((string) $key, '_'), $this->legacy->trueKeys($value))
        ));

        return array_filter([
            'id' => $this->content->rowId('work', (string) $work->id),
            'type' => $type,
            'enabled' => true,
            'title' => $this->legacy->singleLine($work->title ?? ''),
            'caption' => Reps::feature('captions') ? $this->legacy->singleLine($work->caption ?? '') : null,
            'description' => trim(strip_tags($this->legacy->translated($work->description ?? ''))),
            ...$this->workTranslations($work),
            'file' => $this->media->path($work->file ?? null),
            'show_in_overview' => Reps::feature('overview') && (bool) ($work->show_in_overview ?? false),
            'is_portfolio_image' => Reps::feature('portfolio_images') && (bool) ($work->is_artist_portfolio_image ?? false),
            'is_startpage_image' => Reps::feature('startpage_images') && (bool) ($work->is_startpage_image ?? false),
            'is_discipline_image' => Reps::feature('discipline_images') && (bool) ($work->is_artist_discipline_image ?? false),
            'overview_categories' => Reps::feature('overview_categories') ? $categoryIds($work->show_in_overview_category ?? null) : null,
            'represents_in' => Reps::feature('represents_in_categories') ? $categoryIds($work->represents_artist_in_discipline_category ?? null) : null,
            'width_in_overview' => Reps::feature('width_in_overview') && ($work->width_in_overview ?? 'regular') !== 'regular' ? $work->width_in_overview : null,
            'width_in_frame' => Reps::feature('width_in_frame') && ($work->width_in_frame ?? 'full') !== 'full' ? $work->width_in_frame : null,
            'custom_ratio' => trim((string) ($work->custom_ratio ?? '')),
            'bgcolor' => Reps::feature('work_bgcolor') ? ($work->bgcolor ?? null) : null,
            'embed_url' => $type === 'embed' ? $this->embedUrl($work->embed_url ?? null) : null,
            'embed_code' => $type === 'embed' ? trim((string) ($work->embed_code ?? '')) : null,
            'embed_ratio' => $type === 'embed' ? trim((string) ($work->embed_code_ratio ?? '')) : null,
            'textbox_text' => $type === 'textbox' ? $this->content->bard($this->legacy->translated($work->textbox_text ?? '')) : null,
            'textbox_order' => $type === 'textbox' ? ($work->textbox_order ?? null) : null,
            'textbox_fontsize' => $type === 'textbox' ? ($work->textbox_fontsize ?? null) : null,
        ], fn ($v) => $v !== null && $v !== '' && $v !== [] && $v !== false);
    }

    /**
     * Works are not localizable (one list per slideshow); the texts of the other sites are stored on
     * the row as title_<site>, caption_<site>, description_<site> when they differ.
     */
    protected function workTranslations(object $work): array
    {
        $values = [];
        $default = $this->legacy->defaultLocale();

        foreach ($this->legacy->locales() as $locale => $site) {
            if ($locale === $default || ! Reps::feature('work_translations')) {
                continue;
            }

            foreach (['title' => true, 'caption' => Reps::feature('captions'), 'description' => true] as $field => $enabled) {
                if (! $enabled) {
                    continue;
                }

                $translated = $field === 'description'
                    ? trim(strip_tags($this->legacy->translated($work->{$field} ?? '', $locale)))
                    : $this->legacy->singleLine($work->{$field} ?? '', $locale);
                $original = $field === 'description'
                    ? trim(strip_tags($this->legacy->translated($work->{$field} ?? '')))
                    : $this->legacy->singleLine($work->{$field} ?? '');

                $values["{$field}_{$site}"] = $translated !== $original ? $translated : null;
            }
        }

        return $values;
    }

    protected function embedUrl(?string $url): ?string
    {
        $url = trim((string) $url);

        return $url === '' ? null : $url;
    }

    // ---------------------------------------------------------------- pages and content types

    protected function pages(): void
    {
        $rows = $this->legacy->rows('pages');

        foreach ($rows as $row) {
            [$data, $localized] = $this->sites([
                'bg_color' => Reps::feature('page_bgcolor') ? ($row->bg_color ?? null) : null,
                'og_image' => $this->content->ogImage($row),
            ], fn ($locale) => [
                'title' => $this->legacy->singleLine($row->title, $locale),
                'navi_title' => property_exists($row, 'navi_title') ? $this->legacy->singleLine($row->navi_title, $locale) : null,
                'main_content' => $this->content->blocks($row->main_content ?? null, "page|$row->id", $locale),
            ] + $this->content->seo($row, $locale));

            $this->writer->upsert('pages', $row->id, $this->title($row), $data, $localized,
                $this->slugs($row->slug, $this->title($row)),
                published: ! property_exists($row, 'is_published') || (bool) $row->is_published);
        }

        $this->finish('pages', $rows, tree: false);

        // home page first, then the main and footer menu order, then the rest
        $menuOrder = $this->menuItems()->flatten(1)->pluck('value')->filter(fn ($v) => is_numeric($v));
        $order = $rows->where('is_home', 1)->pluck('id')->concat($menuOrder)->concat($rows->pluck('id'))->unique()->values()->all();
        $this->writer->tree('pages', $order);
    }

    protected function news(): void
    {
        $table = $this->legacy->hasTable('posts') ? 'posts' : 'news_items';
        $rows = $this->legacy->rows($table);
        $has = fn ($column) => $this->legacy->hasColumn($table, $column);

        foreach ($rows as $row) {
            [$data, $localized] = $this->sites([
                'type' => $has('type') ? ($row->type ?: null) : null,
                // the old site showed the creation date when no publish date was set
                'publish_date' => $has('publish_date') ? substr((string) ($row->publish_date ?: $row->created_at), 0, 10) ?: null : null,
                'image' => $this->media->path($row->custom_image ?? $row->main_image ?? $row->image ?? null),
                'overview_image' => $has('overview_image') ? $this->media->path($row->overview_image) : null,
                'artist' => $this->writer->id('artists', $row->artist_id ?? null),
                'slideshow' => $this->writer->id('slideshows', $row->slideshow_id ?? null),
                'link' => $has('link') ? $this->content->link($this->legacy->translated($row->link)) : null,
                'is_sticky' => (bool) ($row->is_sticky ?? false),
                'show_logo_as_subline' => (bool) ($row->show_logo_as_subline ?? false),
                'width_in_overview' => ($row->width_in_overview ?? 'regular') !== 'regular' ? $row->width_in_overview : null,
                'width_in_frame' => ($row->width_in_frame ?? 'full') !== 'full' ? $row->width_in_frame : null,
                'title_position' => $row->title_position ?? null,
                'bgcolor' => $row->bgcolor ?? null,
                'og_image' => $this->content->ogImage($row),
            ], fn ($locale) => [
                'title' => $this->legacy->singleLine($row->title, $locale),
                'subtitle' => $has('subtitle') ? $this->legacy->singleLine($row->subtitle, $locale) : null,
                'abstract' => $has('abstract') ? trim($this->legacy->translated($row->abstract, $locale)) : null,
                'text' => $has('text') ? $this->content->bard($this->legacy->translated($row->text, $locale)) : null,
                'image_placeholder' => $has('image_placeholder') ? $this->legacy->singleLine($row->image_placeholder, $locale) : null,
                'main_content' => $this->content->blocks($row->main_content ?? $row->content ?? null, "news|$row->id", $locale),
            ] + $this->content->seo($row, $locale));

            $title = $this->title($row);
            $slug = $has('slug') && filled($row->slug) ? $this->slugs($row->slug, $title) : Str::slug($title).'-'.$row->id;

            $this->writer->upsert('news', $row->id, $title, $data, $localized, $slug,
                published: (bool) ($row->is_published ?? true));
        }

        $this->finish('news', $rows);
    }

    protected function productions(): void
    {
        $rows = $this->legacy->rows('productions');

        foreach ($rows as $row) {
            [$data, $localized] = $this->sites([
                'image' => $this->media->path($row->custom_image ?? null),
                'artist' => $this->writer->id('artists', $row->artist_id ?? null),
                'slideshow' => $this->writer->id('slideshows', $row->slideshow_id ?? null),
            ], fn ($locale) => [
                'title' => $this->legacy->singleLine($row->title, $locale) ?: null,
                'description' => $this->content->bard($this->legacy->translated($row->description, $locale)),
            ]);

            $this->writer->upsert('productions', $row->id, $data['title'] ?? '#'.$row->id, $data, $localized,
                'production-'.$row->id, published: (bool) $row->is_published);
        }

        $this->finish('productions', $rows);
    }

    protected function specialCategories(): void
    {
        $categories = $this->legacy->rows('special_categories');
        $projects = $this->legacy->rows('special_projects');
        $projectArtists = $this->legacy->hasTable('artist_special_project')
            ? $this->legacy->db()->table('artist_special_project')->get()->groupBy('special_project_id')->map->pluck('artist_id')
            : collect();

        foreach ($categories as $row) {
            [$data, $localized] = $this->sites([
                'show_in_navigation' => (bool) $row->show_in_navigation,
                'projects' => $this->writer->ids('special_projects', $projects->where('special_category_id', $row->id)->sortBy('sort_order')->pluck('id')),
                'og_image' => $this->content->ogImage($row),
            ], fn ($locale) => [
                'title' => $this->legacy->singleLine($row->title, $locale),
                'headline' => $this->legacy->singleLine($row->headline ?? '', $locale),
                'intro' => $this->content->bard($this->legacy->translated($row->intro ?? '', $locale)),
            ] + $this->content->seo($row, $locale));

            $this->writer->upsert('special_categories', $row->id, $this->title($row), $data, $localized,
                Str::slug($row->slug), published: (bool) $row->is_published);
        }

        foreach ($projects as $row) {
            [$data, $localized] = $this->sites([
                'special_category' => $this->writer->id('special_categories', $row->special_category_id),
                'overview_image' => $this->media->path($row->overview_image ?? null),
                'artists' => $this->writer->ids('artists', $projectArtists[$row->id] ?? []),
                'og_image' => $this->content->ogImage($row),
            ], fn ($locale) => [
                'title' => $this->legacy->singleLine($row->title, $locale),
                'abstract' => trim($this->legacy->translated($row->abstract ?? '', $locale)),
                'main_content' => $this->content->blocks($row->main_content ?? null, "special_project|$row->id", $locale),
            ] + $this->content->seo($row, $locale));

            $this->writer->upsert('special_projects', $row->id, $this->title($row), $data, $localized,
                Str::slug($row->slug), published: (bool) $row->is_published);
        }

        $this->finish('special_categories', $categories);
        $this->finish('special_projects', $projects, tree: false);
    }

    protected function projects(): void
    {
        $projects = $this->legacy->portfolio('projects');
        $skills = $this->legacy->portfolio('skills');
        $projectArtists = $this->pivot('artist_project', 'project_id', 'artist_id');
        $projectSkills = $this->pivot('project_skill', 'project_id', 'skill_id');
        $skillArtists = $this->pivot('artist_skill', 'skill_id', 'artist_id');

        foreach ($skills as $row) {
            [$data, $localized] = $this->sites([
                'artists' => $this->writer->ids('artists', $skillArtists[$row->id] ?? []),
            ], fn ($locale) => [
                'title' => $this->legacy->singleLine($row->title, $locale),
                'description' => $this->content->bard($this->legacy->translated($row->description ?? '', $locale)),
            ]);

            $this->writer->upsert('skills', $row->id, $this->title($row), $data, $localized, $this->slugs($row->slug, $this->title($row)));
        }

        foreach ($projects as $row) {
            [$data, $localized] = $this->sites([
                'overview_image' => $this->media->path($row->overview_image ?? null),
                'artists' => $this->writer->ids('artists', $projectArtists[$row->id] ?? []),
                'skills' => $this->writer->ids('skills', $projectSkills[$row->id] ?? []),
                'bgcolor' => $row->bgcolor ?? null,
                'og_image' => $this->content->ogImage($row),
            ], fn ($locale) => [
                'title' => $this->legacy->singleLine($row->title, $locale),
                'abstract' => trim($this->legacy->translated($row->abstract ?? '', $locale)),
                'industry' => $this->legacy->singleLine($row->industry ?? '', $locale),
                'format' => $this->legacy->singleLine($row->format ?? '', $locale),
                'skills_text' => $this->legacy->singleLine($row->skills ?? '', $locale),
                'main_content' => $this->content->blocks($row->main_content ?? null, "project|$row->id", $locale),
            ] + $this->content->seo($row, $locale));

            $this->writer->upsert('projects', $row->id, $this->title($row), $data, $localized, $this->slugs($row->slug, $this->title($row)));
        }

        $this->finish('skills', $skills);
        $this->finish('projects', $projects);
    }

    protected function clients(): void
    {
        $rows = $this->legacy->rows('logos');

        foreach ($rows as $row) {
            $title = $this->legacy->singleLine($row->title ?? '') ?: 'Logo '.$row->id;

            $this->writer->upsert('clients', $row->id, $title, [
                'title' => $title,
                'image' => $this->media->path($row->image ?? null),
                'show_on_home' => (bool) ($row->show_on_home ?? false),
            ], slug: 'client-'.$row->id);
        }

        $this->finish('clients', $rows);
    }

    protected function finish(string $collection, Collection $rows, bool $tree = true): void
    {
        if ($this->prune) {
            $this->writer->prune($collection, $rows->pluck('id')->all());
        }

        if ($tree) {
            $this->writer->tree($collection, $rows->sortBy(fn ($row) => [$row->sort_order ?? 0, $row->id])->pluck('id')->all());
        }
    }

    // ---------------------------------------------------------------- globals and navigation

    protected function globals(): void
    {
        $settings = $this->legacy->settings();
        $value = fn ($key, $locale = null) => $this->legacy->translated($settings[$key] ?? '', $locale);

        $this->setGlobals('site_settings', function ($locale) use ($settings, $value) {
            return [
                'website_title' => $this->legacy->singleLine($settings['website_title'] ?? '', $locale),
                'email' => trim($value('email')),
                'phone' => trim($value('phone')),
                'address' => $this->content->bard($value('address', $locale)),
                'social_links' => $this->content->socialLinks($settings['social_links'] ?? null, 'settings'),
                'newsletter_form_link' => trim($value('newsletter_form_link')),
                'newsletter_form_link_text' => $this->legacy->singleLine($settings['newsletter_form_link_text'] ?? '', $locale),
                'analytics_embed_codes' => trim($value('analytics_embed_codes')),
                'analytics_body_embed_codes' => trim($value('analytics_body_embed_codes')),
                'friendly_analytics_embed_codes' => trim($value('friendly_analytics_embed_codes')),
                'verification_embed_codes' => trim($value('verification_embed_codes')),
            ] + $this->extraSettings('site_settings', $locale);
        });

        $this->setGlobals('seo', function ($locale) use ($settings, $value) {
            $values = [];
            $prefixes = ['home' => ''] + collect(Reps::config('seo.index_pages', []))->mapWithKeys(fn ($page, $key) => [$key => "{$key}_"])->all();

            foreach ($prefixes as $key => $prefix) {
                $robots = ['index' => $settings["{$prefix}robots_index"] ?? '1', 'follow' => $settings["{$prefix}robots_follow"] ?? '1'];
                $values += [
                    "{$key}_browser_title" => $this->legacy->singleLine($settings["{$prefix}browser_title"] ?? '', $locale),
                    "{$key}_meta_description" => trim($value("{$prefix}meta_description", $locale)),
                    "{$key}_meta_keywords" => trim($value("{$prefix}meta_keywords", $locale)),
                    "{$key}_og_title" => $this->legacy->singleLine($settings["{$prefix}og_title"] ?? '', $locale),
                    "{$key}_og_description" => trim($value("{$prefix}og_description", $locale)),
                    "{$key}_og_image" => $this->media->path($settings["{$prefix}og_image"] ?? null),
                    "{$key}_robots" => array_values(array_filter([
                        in_array($robots['index'], ['0', 0, false, 'false'], true) ? 'noindex' : null,
                        in_array($robots['follow'], ['0', 0, false, 'false'], true) ? 'nofollow' : null,
                    ])),
                ];
            }

            return $values;
        });
    }

    /**
     * Site-specific settings from legacy.settings: legacy key => [global, field, type], type is
     * text (default), line, bard, asset, assets (comma-separated ids) or raw (decoded JSON).
     */
    protected function extraSettings(string $global, string $locale): array
    {
        $settings = $this->legacy->settings();
        $values = [];

        foreach (Reps::config('legacy.settings', []) as $key => $spec) {
            [$handle, $field, $type, $map] = array_pad($spec, 4, null);

            if ($handle !== $global) {
                continue;
            }

            $raw = $settings[$key] ?? null;
            $values[$field] = $type === 'grid'
                ? $this->settingsGrid($raw, (array) $map, $key, $locale)
                : $this->settingValue($raw, $type ?? 'text', $locale);
        }

        return $values;
    }

    protected function settingValue(mixed $raw, string $type, string $locale): mixed
    {
        return match ($type) {
            'line' => $this->legacy->singleLine($raw ?? '', $locale),
            'bard' => $this->content->bard($this->legacy->translated($raw ?? '', $locale)),
            'asset' => $this->media->path($raw),
            'assets' => $this->media->paths(array_filter(explode(',', (string) $raw))),
            'artist' => $this->writer->id('artists', is_numeric($raw) ? $raw : null),
            'raw' => $this->legacy->json($raw),
            default => trim($this->legacy->translated($raw ?? '', $locale)),
        };
    }

    /** Flexible-content blocks of a setting → grid rows; $map = attribute => type (or [field, type]). */
    protected function settingsGrid(mixed $raw, array $map, string $key, string $locale): array
    {
        return collect($this->legacy->json($raw))->map(function ($block, $i) use ($map, $key, $locale) {
            $row = ['id' => $this->content->rowId('setting', $key, (string) ($block['key'] ?? $i))];

            foreach ($map as $attribute => $type) {
                [$field, $type] = is_array($type) ? $type : [$attribute, $type];
                $value = $block['attributes'][$attribute] ?? null;
                $row[$field] = $type === 'assets'
                    ? $this->media->paths(collect($value)->map(fn ($image) => $image['attributes']['image'] ?? null))
                    : $this->settingValue($value, $type, $locale);
            }

            return array_filter($row, fn ($v) => $v !== null && $v !== '' && $v !== [] && $v !== false);
        })->filter(fn ($row) => count($row) > 1)->values()->all();
    }

    protected function setGlobals(string $handle, callable $values): void
    {
        $set = GlobalSet::findByHandle($handle);

        if (! $set) {
            $this->output->warn("  global set $handle missing, run reps:install first");

            return;
        }

        $default = Site::default()->handle();
        $base = null;
        $locales = $this->legacy->locales();

        // one site, every language in the variables: the other languages go into translated fields
        if (SimpleTranslations::enabled()) {
            $defaultLocale = array_search($default, $locales, true);
            $others = collect($locales)->except($defaultLocale)->map(fn ($site, $locale) => $values($locale))->all();
            $merged = SimpleTranslations::merge($set->blueprint(), $values($defaultLocale), $others);
            $values = fn () => $merged;
            $locales = [$defaultLocale => $default];
        }

        foreach ($locales as $locale => $site) {
            if (! Site::get($site)) {
                continue;
            }

            $variables = $set->in($site) ?? $set->makeLocalization($site);
            $new = $values($locale);
            $changes = [];

            foreach ($new as $key => $value) {
                $blank = $value === null || $value === '' || $value === [];

                // other sites store only what differs from the default site
                if ($site !== $default && $base && Writer::same($base[$key] ?? null, $value)) {
                    $blank = true;
                }

                if ($blank) {
                    if ($variables->data()->has($key)) {
                        $variables->remove($key);
                        $changes[] = $key;
                    }
                } elseif (! Writer::same($variables->data()->get($key), $value)) {
                    $variables->set($key, $value);
                    $changes[] = $key;
                }
            }

            $base ??= $new;

            if ($changes) {
                $this->output->line("  <comment>update</comment> globals $handle [$site] (".implode(', ', $changes).')');

                if (! $this->dryRun) {
                    $variables->save();
                }
            }
        }
    }

    protected function menuItems(): Collection
    {
        if (! $this->legacy->hasTable('nova_menu_menus')) {
            return collect();
        }

        $menus = $this->legacy->db()->table('nova_menu_menus')->pluck('slug', 'id');

        return $this->legacy->db()->table('nova_menu_menu_items')
            ->where('enabled', 1)
            ->orderBy('order')
            ->get()
            ->groupBy(fn ($item) => $menus[$item->menu_id] ?? 'unknown');
    }

    protected function navigations(): void
    {
        $items = $this->menuItems();

        foreach (['main', 'footer'] as $handle) {
            $nav = Nav::findByHandle($handle);

            if (! $nav) {
                continue;
            }

            foreach ($this->legacy->locales() as $locale => $site) {
                if (! Site::get($site)) {
                    continue;
                }

                // nova-menu-builder stores a locale per item, but sites that translate the names in
                // JSON keep every item under one locale: only filter when the locales differ
                $all = $items[$handle] ?? collect();
                $byLocale = $all->pluck('locale')->filter()->unique()->count() > 1;
                $menu = $all
                    ->filter(fn ($item) => ! $byLocale || $item->locale === $locale)
                    ->whereNull('parent_id');

                $branches = $menu->map(fn ($item) => $this->branch($item, $items[$handle], $locale))->filter()->values()->all();

                $tree = $nav->in($site);

                // The eloquent driver saves a tree without a database row as [], so create the row first.
                if (! $tree && ! $this->dryRun) {
                    $tree = tap($nav->makeTree($site))->save();
                }

                $tree ??= $nav->makeTree($site);
                $strip = fn ($branches) => collect($branches)->map(fn ($b) => array_filter(['entry' => $b['entry'] ?? null, 'url' => $b['url'] ?? null, 'title' => $b['title'] ?? null, 'children' => isset($b['children']) ? collect($b['children'])->map(fn ($c) => array_filter(['entry' => $c['entry'] ?? null, 'url' => $c['url'] ?? null, 'title' => $c['title'] ?? null]))->all() : null]))->all();

                if (Writer::same($strip($tree->tree()), $strip($branches))) {
                    continue;
                }

                $this->output->line("  <comment>update</comment> navigation $handle [$site]: ".count($branches).' items');

                if (! $this->dryRun) {
                    $tree->tree($branches)->save();
                }
            }
        }
    }

    protected function branch(object $item, Collection $all, string $locale): ?array
    {
        $title = trim($this->legacy->translated($item->name, $locale));
        $isPage = is_numeric($item->value) && str_contains((string) ($item->class ?? 'Page'), 'Page');
        $entryId = $isPage ? $this->writer->id('pages', $item->value) : null;
        $entry = $entryId ? Entry::find($entryId) : null;
        $entryTitle = $entry ? (SimpleTranslations::read($entry->in(Site::default()->handle()), 'navi_title') ?: $entry->get('title')) : null;

        $children = $all->where('parent_id', $item->id)->map(fn ($child) => $this->branch($child, $all, $locale))->filter()->values()->all();

        $branch = array_filter([
            'id' => $this->content->rowId('nav', (string) $item->id),
            'entry' => $entryId,
            'url' => $isPage ? null : $this->content->link((string) $item->value),
            'title' => $title && $title !== $entryTitle ? $title : null,
            'children' => $children ?: null,
        ]);

        return isset($branch['entry']) || isset($branch['url']) ? $branch : null;
    }
}
