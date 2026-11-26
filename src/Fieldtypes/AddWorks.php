<?php

namespace Kraenkvisuell\StatamicReps\Fieldtypes;

use Kraenkvisuell\StatamicBunnyAssets\Fieldtypes\CdnThumbnailAssets;

/**
 * "Add images" of a slideshow: Statamic's assets field (upload several files at once, or choose
 * them from the container) whose files don't stay in it. Each uploaded or chosen file becomes a
 * work of the set `set` (default `image`, "Image / video") with that set's defaults, appended to
 * the replicator `works_field` (default `works`) of the same form; `reps_add_works-fieldtype` in
 * resources/js/cp.js. The field itself saves nothing.
 */
class AddWorks extends CdnThumbnailAssets
{
    protected static $handle = 'reps_add_works';

    protected $selectable = false;

    public function preProcess($data)
    {
        return [];
    }

    public function process($data)
    {
        return null;
    }

    public function preload()
    {
        return array_merge(parent::preload(), [
            'works_field' => $this->config('works_field', 'works'),
            'set' => $this->config('set', 'image'),
        ]);
    }
}
