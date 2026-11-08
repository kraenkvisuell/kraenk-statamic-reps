<?php

namespace Kraenkvisuell\StatamicReps\Console\Commands;

use Illuminate\Console\Command;
use Kraenkvisuell\StatamicReps\Import\Importer;
use Statamic\Facades\Stache;

/**
 * Imports (and re-imports) the old Nova site from its database dump on the `legacy` connection.
 * Nova is the source until go-live: imported entries are made to match it, entries whose legacy
 * row is gone are deleted (unless --keep-deleted), entries created in Statamic are left alone.
 * A second run with unchanged data reports nothing to do.
 */
class Import extends Command
{
    protected $signature = 'reps:import
        {--only=* : Only these steps (disciplines, categories, artists, slideshows, pages, news, productions, special_categories, projects, clients, globals, navigation)}
        {--no-media : Skip pulling media files}
        {--keep-deleted : Keep imported entries whose legacy row no longer exists}
        {--dry-run : Only report what would change}';

    protected $description = 'Import the old Nova site into the reps content model';

    public function handle(): int
    {
        if (! config('database.connections.legacy.database')) {
            $this->error('LEGACY_DB_DATABASE is not set.');

            return self::FAILURE;
        }

        $importer = new Importer($this, (bool) $this->option('dry-run'), $this->option('only'), ! $this->option('keep-deleted'));
        $importer->run(! $this->option('no-media'));

        $this->newLine();
        $rows = collect($importer->writer->stats)->map(fn ($s, $c) => [$c, $s['created'] ?? 0, $s['updated'] ?? 0, $s['deleted'] ?? 0, $s['unchanged'] ?? 0])->values()->all();
        $this->table(['Collection', 'Created', 'Updated', 'Deleted', 'Unchanged'], $rows);

        if (! $this->option('dry-run')) {
            Stache::clear();
        }

        return self::SUCCESS;
    }
}
