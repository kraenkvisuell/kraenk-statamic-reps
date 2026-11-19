<?php

namespace Kraenkvisuell\StatamicReps\Support;

use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\FilesystemException;

/**
 * Retries failed reads of a disk (Bunny Storage): its replicas are sometimes out of step, so a
 * file that was just written – or has been there for years – answers 404 or an error for a few
 * seconds. Without retries one such read aborts a Glide render, an asset meta write or a whole
 * re-sync. Only failures are retried, so a working disk costs nothing; writes pass through.
 */
class RetryingReads implements FilesystemAdapter
{
    /** @param int $delay milliseconds before the first retry, doubled for every further one */
    public function __construct(
        protected FilesystemAdapter $adapter,
        protected int $retries = 3,
        protected int $delay = 500,
    ) {}

    /**
     * @template T
     *
     * @param  callable(): T  $read
     * @return T
     */
    protected function retry(callable $read): mixed
    {
        for ($attempt = 0; ; $attempt++) {
            try {
                return $read();
            } catch (FilesystemException $e) {
                if ($attempt >= $this->retries) {
                    throw $e;
                }

                usleep($this->delay * (2 ** $attempt) * 1000);
            }
        }
    }

    public function fileExists(string $path): bool
    {
        return $this->retry(fn () => $this->adapter->fileExists($path));
    }

    public function directoryExists(string $path): bool
    {
        return $this->retry(fn () => $this->adapter->directoryExists($path));
    }

    public function read(string $path): string
    {
        return $this->retry(fn () => $this->adapter->read($path));
    }

    public function readStream(string $path)
    {
        return $this->retry(fn () => $this->adapter->readStream($path));
    }

    public function visibility(string $path): FileAttributes
    {
        return $this->retry(fn () => $this->adapter->visibility($path));
    }

    public function mimeType(string $path): FileAttributes
    {
        return $this->retry(fn () => $this->adapter->mimeType($path));
    }

    public function lastModified(string $path): FileAttributes
    {
        return $this->retry(fn () => $this->adapter->lastModified($path));
    }

    public function fileSize(string $path): FileAttributes
    {
        return $this->retry(fn () => $this->adapter->fileSize($path));
    }

    public function listContents(string $path, bool $deep): iterable
    {
        return $this->adapter->listContents($path, $deep);
    }

    public function write(string $path, string $contents, Config $config): void
    {
        $this->adapter->write($path, $contents, $config);
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        $this->adapter->writeStream($path, $contents, $config);
    }

    public function delete(string $path): void
    {
        $this->adapter->delete($path);
    }

    public function deleteDirectory(string $path): void
    {
        $this->adapter->deleteDirectory($path);
    }

    public function createDirectory(string $path, Config $config): void
    {
        $this->adapter->createDirectory($path, $config);
    }

    public function setVisibility(string $path, string $visibility): void
    {
        $this->adapter->setVisibility($path, $visibility);
    }

    public function move(string $source, string $destination, Config $config): void
    {
        $this->adapter->move($source, $destination, $config);
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        $this->adapter->copy($source, $destination, $config);
    }
}
