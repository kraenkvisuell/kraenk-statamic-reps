<?php

namespace Kraenkvisuell\StatamicReps\Lightbox;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Kraenkvisuell\StatamicReps\Portfolio;
use Kraenkvisuell\StatamicReps\Reps;
use Statamic\Facades\Asset;
use Statamic\Facades\Entry;

/**
 * The visitor's lightbox: works collected from slideshows, kept in the session (no account), sorted
 * per artist and downloadable as a PDF. A work is identified by "<slideshow id>:<work row id>".
 */
class Lightbox
{
    protected const KEY = 'reps_lightbox';

    /** @return list<string> */
    public function keys(): array
    {
        return array_values(session(static::KEY, []));
    }

    public function has(string $key): bool
    {
        return in_array($key, $this->keys(), true);
    }

    public function toggle(string $key): bool
    {
        $keys = $this->keys();

        if ($this->has($key)) {
            $keys = array_values(array_diff($keys, [$key]));
        } elseif ($this->resolve($key)) {
            $keys[] = $key;
        }

        session([static::KEY => $keys]);

        return $this->has($key);
    }

    /** New order of one artist's works; the other artists keep theirs. */
    public function order(array $keys): void
    {
        $current = $this->keys();
        $sorted = array_values(array_intersect($keys, $current));
        $positions = array_keys(array_intersect($current, $sorted));

        foreach ($positions as $i => $position) {
            $current[$position] = $sorted[$i];
        }

        session([static::KEY => array_values($current)]);
    }

    public function clear(): void
    {
        session()->forget(static::KEY);
    }

    /**
     * The works grouped by artist, in the visitor's order:
     * [['artist' => Entry, 'works' => [['key', 'slideshow', 'work', 'asset', 'ratio']]]].
     */
    public function grouped(): Collection
    {
        return collect($this->keys())
            ->map(fn ($key) => $this->resolve($key))
            ->filter()
            ->groupBy(fn ($item) => $item['artist']?->id())
            ->map(fn ($items) => ['artist' => $items->first()['artist'], 'works' => $items->values()->all()])
            ->values();
    }

    public function resolve(string $key): ?array
    {
        [$slideshowId, $workId] = array_pad(explode(':', $key, 2), 2, null);
        $slideshow = $slideshowId && Str::isUuid($slideshowId) ? Entry::find($slideshowId) : null;

        if (! $slideshow || $slideshow->collectionHandle() !== 'slideshows' || ! $slideshow->published()) {
            return null;
        }

        $work = collect($slideshow->get('works', []))->firstWhere('id', $workId);
        $asset = ! empty($work['file']) ? Asset::find(Reps::config('assets.container').'::'.$work['file']) : null;

        if (! $work || ! $asset || ! $asset->isImage()) {
            return null;
        }

        return [
            'key' => $key,
            'slideshow' => $slideshow,
            'artist' => Portfolio::artist(collect((array) $slideshow->get('artist'))->first()),
            'work' => $work,
            'asset' => $asset,
            'ratio' => $asset->width() && $asset->height() ? $asset->width() / $asset->height() : 1,
        ];
    }
}
