<?php

namespace Kraenkvisuell\StatamicReps\Import;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Kraenkvisuell\StatamicReps\Reps;
use Statamic\Fields\Field;
use Statamic\Fieldtypes\Bard;
use Statamic\Fieldtypes\Bard\Augmentor;

/**
 * Shared conversions of old Nova values: rich text → Bard, links, flexible-content blocks → sets,
 * deterministic row ids (so re-imports compare equal).
 */
class Content
{
    /** @var array<string, string> old URL path => entry id, for links inside content */
    public array $urls = [];

    public function __construct(protected Legacy $legacy, protected Media $media, protected Writer $writer, protected Command $output) {}

    public function rowId(string ...$parts): string
    {
        return substr(md5(implode('|', $parts)), 0, 8);
    }

    public function row(string $type, string ...$parts): array
    {
        return ['id' => $this->rowId($type, ...$parts), 'type' => $type, 'enabled' => true];
    }

    /**
     * ProseMirror nodes from the old editor HTML. Line breaks and empty paragraphs are kept on
     * purpose: editors used them for spacing.
     */
    public function bard(?string $html): ?array
    {
        $html = str_replace(["\u{200B}", "\r"], '', $html ?? '');
        $html = str_replace("\n", ' ', $html);
        $html = preg_replace('/<\/?span[^>]*>/', '', $html);
        $html = preg_replace('/<\/?div[^>]*>/', '', $html);
        $html = preg_replace('/<a [^>]*>\s*<\/a>/', '', $html);
        $html = preg_replace_callback('/href="([^"]*)"/', fn ($m) => 'href="'.$this->richTextLink(html_entity_decode($m[1])).'"', $html);

        if (trim(strip_tags($html, '<img><iframe><br>')) === '') {
            return null;
        }

        if (! preg_match('/^\s*<(p|h[1-6]|ul|ol|blockquote|table)[\s>]/i', $html)) {
            $html = "<p>{$html}</p>";
        }

        $bard = (new Bard)->setField(new Field('text', ['type' => 'bard']));

        return (new Augmentor($bard))->renderHtmlToProsemirror($html)['content'] ?: null;
    }

    public function richTextLink(string $url): string
    {
        $link = $this->link($url);

        return Str::startsWith((string) $link, 'entry::') ? 'statamic://'.$link : htmlspecialchars($link ?? $url);
    }

    /**
     * A link field value. Links to the old site become entry references where an entry has that
     * URL; URLs without a scheme get https:// like nova_cms_parse_link() did.
     */
    public function link(?string $url): ?string
    {
        $url = trim($url ?? '');

        if ($url === '') {
            return null;
        }

        if ($entry = $this->entryForUrl($url)) {
            return "entry::$entry";
        }

        if (preg_match('~^([a-z]+:|/|#|\?)~i', $url)) {
            return $this->relativeToOldSite($url);
        }

        return "https://$url";
    }

    protected function relativeToOldSite(string $url): string
    {
        $base = Reps::config('legacy.url');
        $host = $base ? parse_url($base, PHP_URL_HOST) : null;

        if ($host && in_array(Str::after((string) parse_url($url, PHP_URL_HOST), 'www.'), [$host, Str::after($host, 'www.')], true)) {
            return Str::after($url, $host) ?: '/';
        }

        return $url;
    }

    protected function entryForUrl(string $url): ?string
    {
        if (str_contains($url, '#')) {
            return null;
        }

        $path = parse_url($this->relativeToOldSite($url), PHP_URL_PATH);

        if (! $path || parse_url($this->relativeToOldSite($url), PHP_URL_HOST)) {
            return null;
        }

        return $this->urls['/'.trim($path, '/')] ?? null;
    }

    /**
     * nova-cms flexible content blocks → main_content sets. Hidden blocks are skipped.
     *
     * @param  string  $owner  a unique key of the owning row (for stable row ids)
     */
    public function blocks(mixed $blocks, string $owner, ?string $locale = null): array
    {
        $sets = [];

        foreach ($this->legacy->json($blocks) as $i => $block) {
            $a = $block['attributes'] ?? [];

            if (! empty($a['hide'])) {
                continue;
            }

            $t = fn ($key) => $this->legacy->translated($a[$key] ?? '', $locale);
            $line = fn ($key) => $this->legacy->singleLine($a[$key] ?? '', $locale);
            $key = $block['key'] ?? (string) $i;

            $set = match ($block['layout'] ?? null) {
                'text' => [
                    'topline' => $line('topline'),
                    'headline' => $line('headline'),
                    'headline_link' => $this->link($t('headline_link')),
                    'text' => $this->bard($t('text')),
                    'is_centered' => (bool) ($a['is_centered'] ?? $a['center_text'] ?? false),
                    'is_collapsed' => (bool) ($a['collapsed'] ?? $a['is_collapsed'] ?? false),
                    'headline_for_folded' => $line('headline_for_folded'),
                    'content_position' => $a['content_position'] ?? null,
                    'bottom_links' => $this->bottomLinks($a['bottom_links'] ?? [], "$owner|$key", $locale),
                ],
                'text-images' => [
                    'topline' => $line('topline'),
                    'headline' => $line('headline'),
                    'headline_link' => $this->link($t('headline_link')),
                    'text' => $this->bard($t('text')),
                    'images_position' => $a['images_position'] ?? null,
                    'images' => collect($a['images'] ?? [])->map(fn ($image, $j) => array_filter([
                        'id' => $this->rowId($owner, $key, 'image', (string) $j),
                        'image' => $this->media->path($image['attributes']['image'] ?? $image['attributes']['file'] ?? null),
                        'caption' => $this->legacy->singleLine($image['attributes']['caption'] ?? '', $locale),
                        'credits' => $this->legacy->singleLine($image['attributes']['credits'] ?? '', $locale),
                        'alt' => $this->legacy->singleLine($image['attributes']['alt'] ?? '', $locale),
                    ]))->filter(fn ($row) => isset($row['image']))->values()->all(),
                    'bottom_links' => $this->bottomLinks($a['bottom_links'] ?? [], "$owner|$key", $locale),
                ],
                'image' => [
                    'image' => $this->media->path($a['image'] ?? null),
                    'topline' => $line('topline'),
                    'headline' => $line('headline'),
                    'caption' => $line('caption'),
                    'subcaption' => $line('subcaption'),
                    'credits' => $line('credits'),
                    'link' => $this->link($t('link')),
                    'open_link_in_new_tab' => (bool) ($a['open_link_in_new_tab'] ?? false),
                    'width' => $a['width'] ?? $a['image_width'] ?? null,
                    'content_position' => $a['content_position'] ?? null,
                ],
                'gallery' => [
                    'headline' => $line('headline'),
                    'slides' => collect($a['slides'] ?? [])->map(fn ($slide, $j) => array_filter([
                        'id' => $this->rowId($owner, $key, 'slide', (string) $j),
                        'image' => $this->media->path($slide['attributes']['image'] ?? null),
                        'caption' => $this->legacy->translated($slide['attributes']['caption'] ?? '', $locale),
                        'link' => $this->link($this->legacy->translated($slide['attributes']['link'] ?? '', $locale)),
                        'open_link_in_new_tab' => (bool) ($slide['attributes']['open_link_in_new_tab'] ?? false),
                    ]))->filter(fn ($row) => isset($row['image']))->values()->all(),
                    'max_columns' => is_numeric($a['max_columns'] ?? null) ? (int) $a['max_columns'] : null,
                    'ratio' => $a['ratio'] ?? null,
                    'download_file' => $this->media->path($a['download_file'] ?? null),
                ],
                'video' => [
                    'topline' => $line('topline'),
                    'headline' => $line('headline'),
                    'embed_url' => trim($t('embed_url')) ?: null,
                    'embed_code' => trim($t('embed_code')) ?: null,
                    'file' => $this->media->path($a['file'] ?? null),
                    'caption' => $line('caption'),
                    'width' => $a['width'] ?? null,
                ],
                'people' => [
                    'headline' => $line('headline'),
                    'people' => collect($a['people'] ?? [])->map(fn ($person, $j) => array_filter([
                        'id' => $this->rowId($owner, $key, 'person', (string) $j),
                        'name' => $this->legacy->singleLine($person['attributes']['name'] ?? '', $locale),
                        'role' => $this->legacy->singleLine($person['attributes']['role'] ?? '', $locale),
                        'image' => $this->media->path($person['attributes']['image'] ?? null),
                        'description' => $this->bard($this->legacy->translated($person['attributes']['description'] ?? '', $locale)),
                    ]))->values()->all(),
                ],
                default => null,
            };

            if ($set === null) {
                $this->output->warn("  unknown block layout '".($block['layout'] ?? '?')."' in $owner, skipped");

                continue;
            }

            $type = str_replace('-', '_', $block['layout']);
            $anchor = $line('anchor');
            $set['anchor'] = $anchor ? Str::slug($anchor) : null;

            $sets[] = $this->row($type, $owner, $key) + array_filter($set, fn ($v) => $v !== null && $v !== '' && $v !== [] && $v !== false);
        }

        return $sets;
    }

    public function bottomLinks(mixed $links, string $owner, ?string $locale = null): array
    {
        return collect($this->legacy->json($links))->map(function ($link, $i) use ($owner, $locale) {
            $a = $link['attributes'] ?? [];
            $file = $this->media->path($a['file'] ?? null);

            return array_filter([
                'id' => $this->rowId($owner, 'link', (string) $i),
                'link_text' => $this->legacy->singleLine($a['link_text'] ?? '', $locale),
                'link' => $this->link($this->legacy->translated($a['link_url'] ?? '', $locale))
                    ?? (($a['link_page_id'] ?? null) ? 'entry::'.$this->writer->id('pages', $a['link_page_id']) : null),
                'file' => $file,
            ]);
        })->filter(fn ($row) => isset($row['link_text']) && (isset($row['link']) || isset($row['file'])))->values()->all();
    }

    public function socialLinks(mixed $blocks, string $owner): array
    {
        return collect($this->legacy->json($blocks))->map(function ($link, $i) use ($owner) {
            $a = $link['attributes'] ?? [];

            return array_filter([
                'id' => $this->rowId($owner, 'social', (string) $i),
                'title' => $this->legacy->singleLine($a['link_title'] ?? ''),
                'url' => trim($this->legacy->translated($a['link_url'] ?? '')),
                'network' => Str::slug($a['slug'] ?? $a['link_icon'] ?? $this->legacy->translated($a['link_title'] ?? '')),
            ]);
        })->filter(fn ($row) => isset($row['url']))->values()->all();
    }

    /** SEO fields of a Nova row in one locale. */
    public function seo(object $row, ?string $locale = null): array
    {
        $robots = $this->legacy->json($row->robots ?? null);

        return [
            'browser_title' => property_exists($row, 'browser_title') ? $this->legacy->singleLine($row->browser_title, $locale) : null,
            'meta_description' => property_exists($row, 'meta_description') ? trim($this->legacy->translated($row->meta_description, $locale)) : null,
            'meta_keywords' => property_exists($row, 'meta_keywords') ? trim($this->legacy->translated($row->meta_keywords, $locale)) : null,
            'robots' => array_values(array_filter([
                $robots && empty($robots['index']) ? 'noindex' : null,
                $robots && empty($robots['follow']) ? 'nofollow' : null,
            ])),
            'og_title' => property_exists($row, 'og_title') ? $this->legacy->singleLine($row->og_title, $locale) : null,
            'og_description' => property_exists($row, 'og_description') ? trim($this->legacy->translated($row->og_description, $locale)) : null,
        ];
    }

    public function ogImage(object $row): ?string
    {
        return property_exists($row, 'og_image') ? $this->media->path($row->og_image) : null;
    }
}
