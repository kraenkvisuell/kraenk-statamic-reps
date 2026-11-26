<?php

namespace Kraenkvisuell\StatamicReps\Http\Controllers\Cp;

use Illuminate\Http\Request;
use Kraenkvisuell\StatamicReps\Fieldtypes\Slideshows;
use Kraenkvisuell\StatamicReps\Listeners\SyncRelations;
use Statamic\Facades\Entry;
use Statamic\Http\Controllers\CP\CpController;

/** Row data and actions of the artist's slideshows field (Fieldtypes\Slideshows). */
class SlideshowItemController extends CpController
{
    /** `?category=<id>`: the row of the category's slideshows field. */
    public function show(Request $request, string $id)
    {
        $slideshow = $this->slideshow($id, 'view');

        return Slideshows::details($slideshow, $request->query('category') ?: null);
    }

    /** Toggles `published` or `visible_in_overview` and saves the slideshow right away (like the Nova actions). */
    public function toggle(Request $request, string $id)
    {
        $slideshow = $this->slideshow($id, 'edit');

        match ($request->validate(['action' => 'required|in:published,visible_in_overview'])['action']) {
            'published' => $slideshow->published(! $slideshow->published()),
            'visible_in_overview' => $slideshow->root()
                ->set('is_visible_in_overview', $slideshow->root()->get('is_visible_in_overview', true) === false),
        };

        $slideshow->save();
        if ($slideshow->root() !== $slideshow) {
            $slideshow->root()->save();
        }

        return array_merge(Slideshows::details(Entry::find($id)), [
            'status' => $slideshow->status(),
        ]);
    }

    /**
     * "Add slideshow" above the list: the new slideshow joined its artist at the end on save
     * (SyncRelations); this puts it first in the saved list too, as the form already shows it.
     * Saved with events, so the artist's pages are re-cached in the new order.
     */
    public function first(string $id)
    {
        $slideshow = $this->slideshow($id, 'edit')->root();
        ['parent' => $parentField, 'field' => $field] = SyncRelations::RELATIONS['artists'];
        $artistId = collect((array) $slideshow->get($parentField))->first();
        $artist = $artistId ? Entry::find($artistId) : null;

        abort_unless($artist, 404);
        $this->authorize('edit', $artist);

        $ids = (array) $artist->get($field, []);
        $first = [$slideshow->id(), ...array_values(array_diff($ids, [$slideshow->id()]))];

        if ($first !== $ids) {
            $artist->set($field, $first)->save();
        }

        return [$field => $first];
    }

    protected function slideshow(string $id, string $ability)
    {
        $slideshow = Entry::find($id);

        abort_unless($slideshow && $slideshow->collectionHandle() === 'slideshows', 404);
        $this->authorize($ability, $slideshow);

        return $slideshow;
    }
}
