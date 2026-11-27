<?php

namespace Kraenkvisuell\StatamicReps\Fieldtypes;

use Statamic\Fieldtypes\Entries;

/**
 * The slideshow of a news item: an entries select that only offers the slideshows of the artist
 * chosen in the field `artist_field` (default `artist`) of the same form, none while no artist is
 * chosen. `reps_artist_slideshows-fieldtype` in resources/js/cp.js sends the artist with every
 * request (`?artist=`), reloads the options when it changes and clears the slideshow then.
 * Statamic's own select loads every slideshow at once (no pagination), thousands on some sites.
 */
class ArtistSlideshows extends Entries
{
    protected static $handle = 'reps_artist_slideshows';

    protected $selectable = false;

    protected $component = 'reps_artist_slideshows';

    public function preload()
    {
        return array_merge(parent::preload(), [
            'artist_field' => $this->config('artist_field', 'artist'),
        ]);
    }

    protected function getIndexQuery($request)
    {
        $query = parent::getIndexQuery($request);

        if ($request->has('artist')) {
            $query->where($this->config('artist_field', 'artist'), $request->input('artist') ?: '__none__');
        }

        return $query;
    }
}
