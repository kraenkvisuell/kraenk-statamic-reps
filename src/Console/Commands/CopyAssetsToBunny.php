<?php

namespace Kraenkvisuell\StatamicReps\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use League\Flysystem\StorageAttributes;

/**
 * Copies the files of the local asset disk (storage/app/public/assets, including the .meta/
 * folders Statamic needs) to the bunny-assets disk with the same paths, so the main container
 * can be switched to that disk. Files that exist on Bunny with the same size and are not newer
 * locally are skipped, so it can be re-run to upload only what changed.
 */
#[Signature('reps:copy-assets-to-bunny
    {--from=assets : Source disk (the local asset disk)}
    {--to=bunny-assets : Destination disk}
    {--without-meta : Leave the .meta/ folders alone (on both sides)}
    {--delete : Delete files on the destination that are missing on the source}
    {--force : Overwrite files that already exist on the destination}
    {--dry-run : Only list what would be copied or deleted}')]
#[Description('Copy the files of the local assets disk to the bunny-assets disk')]
class CopyAssetsToBunny extends Command
{
    /** Files that never belong on the CDN. */
    protected array $ignored = ['.DS_Store', '.gitkeep', 'Thumbs.db'];

    public function handle(): int
    {
        $from = Storage::disk($this->option('from'));
        $to = Storage::disk($this->option('to'));

        $source = $this->listing($from);
        $this->info(sprintf(
            'Found %d files on "%s" (%s).',
            count($source),
            $this->option('from'),
            Number::fileSize(array_sum(array_column($source, 'size')))
        ));

        // One listing of the destination instead of a HEAD request per file.
        $existing = $this->listing($to);

        $toCopy = array_keys(array_filter($source, fn (array $file, string $path) => $this->option('force')
            || ! isset($existing[$path])
            || $existing[$path]['size'] !== $file['size']
            || $existing[$path]['modified'] < $file['modified'], ARRAY_FILTER_USE_BOTH));
        $toDelete = $this->option('delete') ? array_keys(array_diff_key($existing, $source)) : [];

        if ($skipped = count($source) - count($toCopy)) {
            $this->line("Skipping {$skipped} files that are already up to date.");
        }

        if (! $toCopy && ! $toDelete) {
            $this->info('Nothing to do.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            foreach ($toCopy as $path) {
                $this->line("  copy    {$path}");
            }
            foreach ($toDelete as $path) {
                $this->line("  delete  {$path}");
            }
            $this->info(sprintf('Dry run: %d files would be copied, %d deleted.', count($toCopy), count($toDelete)));

            return self::SUCCESS;
        }

        $failed = [];

        if ($toCopy) {
            $bar = $this->output->createProgressBar(count($toCopy));
            $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %message%');
            $bar->start();

            foreach ($toCopy as $path) {
                $bar->setMessage($path);
                $stream = $from->readStream($path);

                // one PUT per file (S3 allows up to 5 GB): multipart uploads to Bunny failed with TLS errors
                if (! $stream || ! $to->writeStream($path, $stream, ['mup_threshold' => 5 * 1024 ** 3])) {
                    $failed[] = $path;
                }

                if (is_resource($stream)) {
                    fclose($stream);
                }

                $bar->advance();
            }

            $bar->setMessage('');
            $bar->finish();
            $this->newLine(2);
        }

        foreach ($toDelete as $path) {
            if (! $to->delete($path)) {
                $failed[] = $path;
            }
        }

        if ($failed) {
            $this->error(count($failed).' files could not be copied or deleted:');
            foreach ($failed as $path) {
                $this->line("  {$path}");
            }

            return self::FAILURE;
        }

        $this->info(sprintf('Copied %d files to "%s", deleted %d.', count($toCopy), $this->option('to'), count($toDelete)));

        return self::SUCCESS;
    }

    /**
     * @return array<string, array{size: int, modified: int}> path => size and last modified time
     */
    protected function listing(FilesystemAdapter $disk): array
    {
        $files = [];

        foreach ($disk->listContents('', true) as $item) {
            /** @var StorageAttributes $item */
            $path = $item->path();

            if (! $item->isFile()
                || in_array(basename($path), $this->ignored)
                || ($this->option('without-meta') && str_contains("/{$path}", '/.meta/'))) {
                continue;
            }

            $files[$path] = ['size' => $item->fileSize(), 'modified' => $item->lastModified()];
        }

        return $files;
    }
}
