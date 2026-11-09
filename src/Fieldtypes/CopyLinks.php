<?php

namespace Kraenkvisuell\StatamicReps\Fieldtypes;

use Statamic\Fields\Fieldtype;

/**
 * The "Copy Link" buttons of the CP's artist list (feature artist_copy_links). The value is a
 * computed list of [label, text] (Cp\ArtistListing::copyLinks); resources/js/cp.js renders them
 * as Statamic buttons that copy the text. Listing only, like reps_html.
 */
class CopyLinks extends Fieldtype
{
    protected static $handle = 'reps_copy_links';

    protected $selectable = false;

    public function preProcessIndex($value)
    {
        return array_values((array) $value);
    }
}
