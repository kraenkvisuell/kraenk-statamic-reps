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
 * between runs. The local `assets` disk (public/assets) is the mirror of the old originals; when the
 * container is on Bunny, only new and replaced files are uploaded from there. Glide images are
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

        $mirror = Storage::disk('assets');
        $paths = array_keys($this->used);
        $this->output->line('  '.count($paths).' media files used by the content');

        [$new, $replaced] = match (Reps::config('legacy.media_source')) {
            's3' => $this->pullFromDisk($paths),
            'http' => $this->pullFromUrl($paths),
            default => $this->pullFromServer($paths),
        };

        $absent = array_values(array_filter($paths, fn ($path) => ! $this->dryRun && ! $mirror->exists($path)));

        if ($absent) {
            $this->output->warn('  '.count($absent).' files are missing on the old site too: '.implode(', ', array_slice($absent, 0, 10)));
        }

        $container = AssetContainer::findByHandle(Reps::config('assets.container'));
        $remote = $container->diskHandle() !== 'assets';

        // assets whose meta doesn't exist yet: new files, or files imported before without meta
        $withoutMeta = array_values(array_filter(
            array_diff($paths, $absent),
            fn ($path) => ! $container->disk()->exists(dirname($path) === '.' ? '.meta/'.basename($path).'.yaml' : dirname($path).'/.meta/'.basename($path).'.yaml'),
        ));

        $upload = array_values(array_unique(array_merge($new, $replaced, $remote ? array_intersect($withoutMeta, $paths) : [])));

        if ($remote && $upload) {
            $this->output->line('  upload to '.$container->diskHandle().': '.count($upload).' files');

            if (! $this->dryRun) {
                foreach ($upload as $path) {
                    if ($mirror->exists($path)) {
                        $container->disk()->filesystem()->writeStream($path, $mirror->readStream($path));
                    }
                }
            }
        }

        $metas = array_values(array_unique(array_merge($withoutMeta, $replaced)));

        if ($metas) {
            $this->output->line('  asset meta: '.count($metas).' files');
        }

        if ($this->dryRun) {
            if ($replaced) {
                $this->output->line('  Glide images to clear (replaced files): '.count($replaced));
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

        foreach ($replaced as $path) {
            if ($asset = $container->asset($path)) {
                Glide::clearAsset($asset);
            }
        }

        if ($replaced) {
            $this->output->line('  cleared the Glide images of '.count($replaced).' replaced files');
        }
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

        $list = storage_path('reps/media-files.txt');
        File::ensureDirectoryExists(dirname($list));
        File::put($list, implode("\n", $paths)."\n");

        $target = rtrim(Storage::disk('assets')->path(''), '/').'/';
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
        $mirror = Storage::disk('assets');
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
        $mirror = Storage::disk('assets');
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
