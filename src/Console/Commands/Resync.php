<?php

namespace Kraenkvisuell\StatamicReps\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Number;
use Kraenkvisuell\StatamicReps\Import\Importer;
use Kraenkvisuell\StatamicReps\Reps;
use Statamic\Facades\Entry;
use Statamic\Facades\Stache;

/**
 * Re-syncs the new site from the live Nova site until go-live:
 *
 * 1. dumps the Nova database on the old server (read-only, over ssh) into the local legacy database,
 * 2. without --apply: reports what an import would change,
 *    with --apply: backs up the content tables, imports (entries, globals, navigations) and syncs
 *    the media incrementally – rsync lists new and replaced files, only those are uploaded to Bunny
 *    and only the Glide images of replaced files are cleared (asset paths never change),
 * 3. renders the pages of the entries that changed, so their new images are generated before
 *    visitors ask for them. Nothing else is warmed again.
 *
 * Undo an --apply with --restore=<backup file>.
 */
class Resync extends Command
{
    protected $signature = 'reps:resync
        {--apply : Change the site (otherwise only report)}
        {--skip-dump : Use the legacy database as it is}
        {--no-media : Don\'t sync media}
        {--no-warm : Don\'t render the changed pages}
        {--restore= : Restore the content tables from a backup file and stop}';

    protected $description = 'Pull the live Nova site again and apply the differences';

    protected const TABLES = ['entries', 'trees', 'global_set_variables'];

    public function handle(): int
    {
        if ($file = $this->option('restore')) {
            return $this->restore($file);
        }

        if (! $this->option('skip-dump') && ! $this->dump()) {
            return self::FAILURE;
        }

        if (! $this->option('apply')) {
            $this->info('Differences (nothing is changed without --apply):');
            $importer = new Importer($this, true);
            $importer->run(! $this->option('no-media'));
            $this->summary($importer);

            return self::SUCCESS;
        }

        $this->backup();

        $importer = new Importer($this, false);
        $importer->run(! $this->option('no-media'));
        $this->summary($importer);

        Stache::clear();
        $this->callSilently('cache:clear');

        if (! $this->option('no-warm')) {
            $this->warm($importer->writer->touched);
        }

        return self::SUCCESS;
    }

    protected function dump(): bool
    {
        $server = Reps::config('legacy.server');
        $path = Reps::config('legacy.path');
        $db = config('database.connections.legacy');

        if (! $server || ! $path || ! ($db['database'] ?? null)) {
            $this->error('legacy.server, legacy.path and LEGACY_DB_DATABASE must be set.');

            return false;
        }

        $this->info("Dumping the Nova database on $server (read-only) …");
        $file = storage_path('app/reps-legacy-dump.sql.gz');

        $remote = 'cd '.escapeshellarg($path).' && set -a && . ./.env >/dev/null 2>&1; set +a; '
            .'MYSQL_PWD="$DB_PASSWORD" mysqldump --single-transaction --no-tablespaces -h"${DB_HOST:-127.0.0.1}" -u"$DB_USERNAME" "$DB_DATABASE" | gzip';

        $result = Process::timeout(1800)->run(['bash', '-c', 'ssh -o BatchMode=yes '.escapeshellarg($server).' '.escapeshellarg($remote).' > '.escapeshellarg($file)]);

        if (! $result->successful() || filesize($file) < 1000) {
            $this->error('Dump failed: '.trim($result->errorOutput()));

            return false;
        }

        $mysql = rtrim((string) Reps::config('legacy.mysql_bin'), '/');
        $mysql = $mysql && is_executable("$mysql/mysql") ? "$mysql/mysql" : 'mysql';
        $auth = sprintf('-h%s -P%s -u%s', escapeshellarg($db['host']), escapeshellarg((string) $db['port']), escapeshellarg($db['username']));
        $name = str_replace('`', '', $db['database']);

        $load = sprintf(
            'MYSQL_PWD=%s %s %s -e %s && gunzip -c %s | MYSQL_PWD=%s %s %s %s',
            escapeshellarg((string) $db['password']), $mysql, $auth,
            escapeshellarg("DROP DATABASE IF EXISTS `$name`; CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"),
            escapeshellarg($file),
            escapeshellarg((string) $db['password']), $mysql, $auth, escapeshellarg($name),
        );

        $result = Process::timeout(1800)->run(['bash', '-c', $load]);

        if (! $result->successful()) {
            $this->error('Loading the dump failed: '.trim($result->errorOutput()));

            return false;
        }

        DB::purge('legacy');
        $this->line(sprintf('  loaded into %s (%s)', $name, Number::fileSize(filesize($file))));

        return true;
    }

    protected function backup(): void
    {
        $file = storage_path('app/reps-backups/'.now()->format('Y-m-d-His').'.json');
        File::ensureDirectoryExists(dirname($file));

        $data = collect(static::TABLES)->mapWithKeys(fn ($table) => [$table => DB::table($table)->get()->map(fn ($row) => (array) $row)->all()]);
        File::put($file, json_encode($data));

        $this->line('Backup: '.$file);
    }

    protected function restore(string $file): int
    {
        $data = json_decode(File::get($file), true);

        DB::transaction(function () use ($data) {
            foreach (static::TABLES as $table) {
                DB::table($table)->delete();

                foreach (array_chunk($data[$table] ?? [], 500) as $chunk) {
                    DB::table($table)->insert($chunk);
                }
            }
        });

        Stache::clear();
        $this->callSilently('cache:clear');
        $this->info('Restored '.$file);

        return self::SUCCESS;
    }

    /** Renders the pages of changed entries (and their artists' pages) to generate their Glide images. */
    protected function warm(array $ids): void
    {
        $urls = collect($ids)->unique()
            ->map(fn ($id) => Entry::find($id))
            ->filter(fn ($entry) => $entry && $entry->published())
            ->flatMap(function ($entry) {
                $artist = $entry->collectionHandle() === 'slideshows' ? Entry::find(collect((array) $entry->get('artist'))->first()) : null;

                return [$entry->url(), $artist?->url()];
            })
            ->filter()->unique()->values();

        if ($urls->isEmpty()) {
            return;
        }

        $this->info("Rendering {$urls->count()} changed pages …");
        $kernel = app(Kernel::class);

        foreach ($urls as $url) {
            $response = $kernel->handle($request = Request::create(url($url)));
            $kernel->terminate($request, $response);

            if ($response->getStatusCode() !== 200) {
                $this->line("  {$response->getStatusCode()} $url");
            }
        }
    }

    protected function summary(Importer $importer): void
    {
        $rows = collect($importer->writer->stats)->map(fn ($s, $c) => [$c, $s['created'] ?? 0, $s['updated'] ?? 0, $s['deleted'] ?? 0, $s['unchanged'] ?? 0])->values()->all();
        $this->table(['Collection', 'Created', 'Updated', 'Deleted', 'Unchanged'], $rows);
    }
}
