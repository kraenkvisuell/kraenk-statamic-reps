<?php

namespace Kraenkvisuell\StatamicReps\Import;

use Illuminate\Console\Command;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Kraenkvisuell\StatamicReps\Reps;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Glide;

/**
 * The old media library: maps media ids to asset paths and brings the files the content uses
 * into the asset container.
 *
 * The asset path is the old folder + file name (unique, with a timestamp), so it never changes
 * between runs. The `legacy-mirror` disk (storage/app/legacy-media) mirrors the old originals; the
 * local `assets` disk (public/assets) holds hard links to them, or converted copies where needed
 * (see produce()). When the container is on Bunny, only new and replaced files are uploaded. Glide images are
 * cleared only for files that were replaced under the same path, so a re-sync never has to render
 * the whole site again.
 */
class Media
{
    protected ?array $media = null;

    /** @var array<string, int> asset path => media id */
    protected array $used = [];

    protected array $missing = [];

    public function __construct(protected Legacy $legacy, protected Command $output, protected bool $dryRun) {}

    protected function media(): array
    {
        return $this->media ??= $this->legacy->rows('nova_media_library')->all();
    }

    /** Asset path of a media id (marks it as used), null when the id doesn't exist. */
    public function path(mixed $id): ?string
    {
        $id = is_numeric($id) ? (int) $id : 0;

        if (! $id) {
            return null;
        }

        if (! $row = $this->media()[$id] ?? null) {
            $this->missing[$id] = true;

            return null;
        }

        $path = ltrim(trim((string) $row->folder, '/').'/'.$row->name, '/');
        $this->used[$path] = $id;

        return $path;
    }

    /** @return list<string> */
    public function paths(iterable $ids): array
    {
        return collect($ids)->map(fn ($id) => $this->path($id))->filter()->values()->all();
    }

    public function mime(mixed $id): ?string
    {
        $row = $this->media()[(int) $id] ?? null;

        return $row ? ($this->legacy->json($row->options)['mime'] ?? null) : null;
    }

    public function sync(): void
    {
        if ($this->missing) {
            $this->output->warn('  '.count($this->missing).' media ids referenced by content no longer exist (skipped): '.implode(', ', array_slice(array_keys($this->missing), 0, 20)));
        }

        $mirror = Storage::disk('legacy-mirror');
        $local = Storage::disk('assets');
        $paths = array_keys($this->used);
        $this->output->line('  '.count($paths).' media files used by the content');

        $this->adopt($paths);

        [$new, $replaced] = match (Reps::config('legacy.media_source')) {
            's3' => $this->pullFromDisk($paths),
            'http' => $this->pullFromUrl($paths),
            default => $this->pullFromServer($paths),
        };

        $absent = array_values(array_filter($paths, fn ($path) => ! $this->dryRun && ! $mirror->exists($path)));

        if ($absent) {
            $this->output->warn('  '.count($absent).' files are missing on the old site too: '.implode(', ', array_slice($absent, 0, 10)));
        }

        // the asset files: new and replaced originals, and any the local disk doesn't have yet
        $produce = array_values(array_unique(array_merge(
            $new,
            $replaced,
            array_filter(array_diff($paths, $absent), fn ($path) => ! $local->exists($path)),
        )));
        $converted = $this->produce($produce);

        $container = AssetContainer::findByHandle(Reps::config('assets.container'));
        $remote = $container->diskHandle() !== 'assets';

        // assets whose meta doesn't exist yet: new files, or files imported before without meta
        $withoutMeta = array_values(array_filter(
            array_diff($paths, $absent),
            fn ($path) => ! $container->disk()->exists(dirname($path) === '.' ? '.meta/'.basename($path).'.yaml' : dirname($path).'/.meta/'.basename($path).'.yaml'),
        ));

        $changed = array_values(array_unique(array_merge($replaced, $converted)));
        $upload = array_values(array_unique(array_merge($produce, $remote ? $withoutMeta : [])));

        if ($remote && $upload) {
            $this->output->line('  upload to '.$container->diskHandle().': '.count($upload).' files');

            if (! $this->dryRun) {
                foreach ($upload as $path) {
                    if ($local->exists($path)) {
                        $container->disk()->filesystem()->writeStream($path, $local->readStream($path));
                    }
                }
            }
        }

        $metas = array_values(array_unique(array_merge($withoutMeta, $changed)));

        if ($metas) {
            $this->output->line('  asset meta: '.count($metas).' files');
        }

        if ($this->dryRun) {
            if ($changed) {
                $this->output->line('  Glide images to clear (replaced or converted files): '.count($changed));
            }

            return;
        }

        foreach ($metas as $i => $path) {
            $asset = $container->makeAsset($path);
            $asset->hydrate();
            $asset->saveQuietly();

            if (($i + 1) % 1000 === 0) {
                $this->output->line('    '.($i + 1).' …');
            }
        }

        foreach ($changed as $path) {
            if ($asset = $container->asset($path)) {
                Glide::clearAsset($asset);
            }
        }

        if ($changed) {
            $this->output->line('  cleared the Glide images of '.count($changed).' replaced or converted files');
        }
    }

    /**
     * Files synced before the mirror existed sit on the local asset disk: move them into the
     * mirror once (only originals, never files this class converted – those have a .reps marker).
     */
    protected function adopt(array $paths): void
    {
        $mirror = Storage::disk('legacy-mirror');
        $local = Storage::disk('assets');
        $moved = 0;

        foreach ($paths as $path) {
            if ($mirror->exists($path) || ! $local->exists($path)) {
                continue;
            }

            $moved++;

            if (! $this->dryRun) {
                File::ensureDirectoryExists(dirname($mirror->path($path)));
                rename($local->path($path), $mirror->path($path));
            }
        }

        if ($moved) {
            $this->output->line("  moved $moved originals from the asset disk into the mirror");
        }
    }

    /**
     * Puts the originals onto the local asset disk: a hard link when the file can be used as it is,
     * a converted copy when it can't – images with a non-sRGB colour profile (Adobe RGB …) are
     * converted to sRGB (Glide drops profiles, so they would look washed out), and images whose
     * content doesn't match their extension (PNG/WebP named .jpg) are re-encoded (Glide refuses
     * them). The mirror keeps the untouched originals, so re-syncs still compare against the old site.
     *
     * @return list<string> the paths that were converted
     */
    protected function produce(array $paths): array
    {
        $mirror = Storage::disk('legacy-mirror');
        $local = Storage::disk('assets');
        $formats = ['jpg' => 'JPEG', 'jpeg' => 'JPEG', 'png' => 'PNG', 'webp' => 'WEBP', 'gif' => 'GIF'];
        $magick = (string) Reps::config('imaging.magick', 'magick');
        $icc = __DIR__.'/../../resources/icc/sRGB2014.icc';
        $converted = [];

        foreach ($paths as $path) {
            if (! $mirror->exists($path)) {
                continue;
            }

            $source = $mirror->path($path);
            $target = $local->path($path);
            $expected = $formats[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? null;
            $convert = false;
            $profile = false;

            if ($expected) {
                $info = Process::run([$magick, 'identify', '-format', '%m|%[icc:description]\n', $source.'[0]']);
                [$format, $description] = array_pad(explode('|', trim(strtok($info->output(), "\n") ?: '')), 2, '');
                $profile = $description !== '' && ! str_contains($description, 'sRGB') && $expected !== 'GIF';
                $convert = $info->successful() && ($profile || ($format && $format !== $expected));
            }

            if ($convert) {
                $converted[] = $path;
            }

            if ($this->dryRun) {
                continue;
            }

            File::ensureDirectoryExists(dirname($target));
            @unlink($target);

            if ($convert) {
                $args = [$magick, $source];
                if ($profile) {
                    array_push($args, '-profile', $icc);
                }
                array_push($args, '-quality', '92', "$expected:$target");
                $result = Process::timeout(300)->run($args);

                if ($result->successful()) {
                    continue;
                }

                $this->output->warn("  could not convert $path: ".trim($result->errorOutput()));
            }

            if (! @link($source, $target)) {
                copy($source, $target);
            }
        }

        if ($converted) {
            $this->output->line('  converted '.count($converted).' files (colour profile → sRGB, or format ≠ extension)');
        }

        return $converted;
    }

    /**
     * rsync from the old server's storage/app/public, only the listed files. Returns [new, replaced].
     */
    protected function pullFromServer(array $paths): array
    {
        $server = Reps::config('legacy.server');
        $remote = rtrim((string) Reps::config('legacy.path'), '/').'/storage/app/public/';

        if (! $server || ! Reps::config('legacy.path')) {
            $this->output->warn('  legacy.server / legacy.path not set: media not pulled');

            return [[], []];
        }

        $list = storage_path('app/reps-media-files.txt');
        File::ensureDirectoryExists(dirname($list));
        File::put($list, implode("\n", $paths)."\n");

        $target = rtrim(Storage::disk('legacy-mirror')->path(''), '/').'/';
        File::ensureDirectoryExists($target);

        $args = ['rsync', '-a', '--partial', '--timeout=120', '--itemize-changes', "--files-from=$list"];
        if ($this->dryRun) {
            $args[] = '--dry-run';
        }
        array_push($args, "$server:$remote", $target);

        $output = '';
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $result = Process::timeout(0)->run($args);
            $output .= $result->output();

            if ($result->successful()) {
                break;
            }

            // 23 = some files missing on the source; the rest was transferred
            if ($result->exitCode() === 23) {
                break;
            }

            $this->output->warn("  rsync failed ({$result->exitCode()}), retrying ($attempt): ".trim(str($result->errorOutput())->limit(200)));
            sleep(5);
        }

        $new = $replaced = [];
        foreach (explode("\n", $output) as $line) {
            if (preg_match('/^>f\++ (.+)$/', $line, $m)) {
                $new[] = $m[1];
            } elseif (preg_match('/^>f\S* (.+)$/', $line, $m)) {
                $replaced[] = $m[1];
            }
        }

        $this->output->line(sprintf('  rsync: %d new, %d replaced', count($new), count($replaced)));

        return [$new, $replaced];
    }

    /**
     * Downloads from the old public media URL (legacy.media_url) the files that are missing locally
     * or whose size changed (HEAD requests, 20 at a time).
     */
    protected function pullFromUrl(array $paths): array
    {
        $base = rtrim((string) Reps::config('legacy.media_url'), '/');
        $mirror = Storage::disk('legacy-mirror');
        $new = $replaced = [];
        $url = fn ($path) => $base.'/'.implode('/', array_map('rawurlencode', explode('/', $path)));

        $existing = array_values(array_filter($paths, fn ($path) => $mirror->exists($path)));
        $new = array_values(array_diff($paths, $existing));

        foreach (array_chunk($existing, 20) as $chunk) {
            $responses = Http::pool(fn ($pool) => array_map(fn ($path) => $pool->as($path)->timeout(30)->head($url($path)), $chunk));

            foreach ($chunk as $path) {
                $response = $responses[$path] ?? null;
                $length = $response instanceof Response && $response->successful() ? (int) $response->header('Content-Length') : 0;

                if ($length && $length !== $mirror->size($path)) {
                    $replaced[] = $path;
                }
            }
        }

        if (! $this->dryRun) {
            foreach (array_chunk(array_merge($new, $replaced), 10) as $chunk) {
                $responses = Http::pool(fn ($pool) => array_map(fn ($path) => $pool->as($path)->timeout(300)->get($url($path)), $chunk));

                foreach ($chunk as $path) {
                    $response = $responses[$path] ?? null;

                    if ($response instanceof Response && $response->successful()) {
                        $mirror->put($path, $response->body());
                    } else {
                        $new = array_values(array_diff($new, [$path]));
                        $replaced = array_values(array_diff($replaced, [$path]));
                    }
                }
            }
        }

        $this->output->line(sprintf('  media: %d new, %d replaced', count($new), count($replaced)));

        return [$new, $replaced];
    }

    /** Copies from an S3 disk (legacy.media_disk) the files that are missing or differ in size. */
    protected function pullFromDisk(array $paths): array
    {
        $source = Storage::disk(Reps::config('legacy.media_disk'));
        $mirror = Storage::disk('legacy-mirror');
        $new = $replaced = [];

        foreach ($paths as $path) {
            $exists = $mirror->exists($path);

            if ($exists && $mirror->size($path) === $source->size($path)) {
                continue;
            }

            $exists ? $replaced[] = $path : $new[] = $path;

            if (! $this->dryRun && $source->exists($path)) {
                $mirror->writeStream($path, $source->readStream($path));
            }
        }

        $this->output->line(sprintf('  media: %d new, %d replaced', count($new), count($replaced)));

        return [$new, $replaced];
    }
}
