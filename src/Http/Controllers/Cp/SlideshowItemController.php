<?php

namespace Kraenkvisuell\StatamicReps\Http\Controllers\Cp;

use Illuminate\Http\Request;
use Kraenkvisuell\StatamicReps\Fieldtypes\Slideshows;
use Statamic\Facades\Entry;
use Statamic\Http\Controllers\CP\CpController;

/** Row data and actions of the artist's slideshows field (Fieldtypes\Slideshows). */
class SlideshowItemController extends CpController
{
    public function show(string $id)
    {
        $slideshow = $this->slideshow($id, 'view');

        return Slideshows::details($slideshow);
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

    protected function slideshow(string $id, string $ability)
    {
        $slideshow = Entry::find($id);

        abort_unless($slideshow && $slideshow->collectionHandle() === 'slideshows', 404);
        $this->authorize($ability, $slideshow);

        return $slideshow;
    }
}
