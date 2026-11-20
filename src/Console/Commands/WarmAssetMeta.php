<?php

namespace Kraenkvisuell\StatamicReps\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Kraenkvisuell\StatamicBunnyAssets\Console\Commands\WarmAssetMeta as BunnyWarmAssetMeta;

/**
 * The old name of statamic-bunny-assets's `bunny-assets:warm-asset-meta`, kept for deploy scripts.
 */
#[Signature('reps:warm-asset-meta {container? : Container handle (default: all)} {--concurrency=32 : Parallel requests on S3 disks} {--force : Reload metadata that is cached already}')]
#[Description('Load the metadata of all assets into the meta cache (same as bunny-assets:warm-asset-meta)')]
class WarmAssetMeta extends BunnyWarmAssetMeta {}
