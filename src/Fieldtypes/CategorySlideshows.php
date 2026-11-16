<?php

namespace Kraenkvisuell\StatamicReps\Fieldtypes;

use Kraenkvisuell\StatamicReps\Portfolio;
use Kraenkvisuell\StatamicReps\Reps;
use Statamic\Contracts\Entries\Entry as EntryContract;

/**
 * The slideshows of a category, in the order of the category page (`slideshow_order`), like the
 * old Nova list: the slideshows the category page shows are listed, only the order is edited here. Which
 * categories a slideshow is in is set in the slideshow. Rows as in the artist's slideshows field,
 * titled "Artist / Slideshow"; `reps_category_slideshows-fieldtype` in resources/js/cp.js has no
 * link, create or unlink buttons.
 */
class CategorySlideshows extends Slideshows
{
    protected static $handle = 'reps_category_slideshows';

    protected $component = 'reps_category_slideshows';

    protected $canCreate = false;

    public function preProcess($data)
    {
        $category = $this->field?->parent();

        if (! $category instanceof EntryContract || ! $category->id()) {
            return [];
        }

        $categoryId = $category->root()->id();

        return Portfolio::orderedCategorySlideshows($categoryId, (array) $data)
            ->filter(fn ($slideshow) => static::shownOnCategoryPage($slideshow, $categoryId))
            ->map->id()->values()->all();
    }

    /**
     * With `represents_in_categories` a category page shows only the slideshows with a work chosen
     * to represent the artist in it, and only those are listed (like Nova's
     * `category_slideshows_are_filtered`); the others don't need an order.
     */
    protected static function shownOnCategoryPage(EntryContract $slideshow, string $categoryId): bool
    {
        return ! Reps::feature('represents_in_categories') || collect((array) $slideshow->get('works', []))
            ->contains(fn ($work) => in_array($categoryId, (array) ($work['represents_in'] ?? []), true));
    }

    protected function toItemArray($id)
    {
        $item = parent::toItemArray($id);

        if (! ($item['invalid'] ?? false) && ($slideshow = $this->findEntry($id))) {
            $artist = Portfolio::artist($slideshow->root()->get('artist'));
            $item['title'] = $artist ? $artist->root()->get('title').' / '.$item['title'] : $item['title'];
        }

        return $item;
    }
}
