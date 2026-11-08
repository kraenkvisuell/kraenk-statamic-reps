<?php

namespace Kraenkvisuell\StatamicReps\Support;

use Kraenkvisuell\StatamicReps\Listeners\AdjustBlueprints;
use Kraenkvisuell\StatamicReps\Reps;
use Statamic\Facades\Blueprint;

/**
 * Blueprints of the global sets, used when the site has no resources/blueprints/globals/<handle>.yaml.
 */
class GlobalBlueprints
{
    public const HANDLES = ['site_settings', 'seo'];

    public static function make(string $handle): \Statamic\Fields\Blueprint
    {
        $contents = match ($handle) {
            'site_settings' => static::siteSettings(),
            'seo' => static::seo(),
        };

        return Blueprint::make()->setContents(AdjustBlueprints::adjust($contents, $handle));
    }

    protected static function siteSettings(): array
    {
        $code = fn (string $display, string $instructions = '') => [
            'type' => 'code', 'display' => $display, 'mode' => 'htmlmixed', 'instructions' => $instructions, 'localizable' => false,
        ];

        return ['tabs' => [
            'contact' => ['display' => 'Contact', 'sections' => [['fields' => [
                ['handle' => 'website_title', 'field' => ['type' => 'text', 'display' => 'Website title', 'localizable' => true]],
                ['handle' => 'email', 'field' => ['type' => 'text', 'display' => 'Email', 'input_type' => 'email', 'width' => 50]],
                ['handle' => 'phone', 'field' => ['type' => 'text', 'display' => 'Phone', 'width' => 50]],
                ['handle' => 'address', 'field' => ['type' => 'bard', 'display' => 'Address', 'buttons' => ['bold', 'italic', 'anchor'], 'save_html' => false, 'localizable' => true]],
                ['import' => 'reps::social_links'],
                ['handle' => 'newsletter_form_link', 'field' => ['type' => 'text', 'display' => 'Newsletter link', 'width' => 50]],
                ['handle' => 'newsletter_form_link_text', 'field' => ['type' => 'text', 'display' => 'Newsletter link text', 'width' => 50, 'localizable' => true]],
            ]]]],
            'scripts' => ['display' => 'Scripts', 'sections' => [['fields' => [
                ['handle' => 'analytics_embed_codes', 'field' => $code('Analytics (head)', 'Only loaded after consent to statistics cookies.')],
                ['handle' => 'analytics_body_embed_codes', 'field' => $code('Analytics (body)', 'Only loaded after consent to statistics cookies.')],
                ['handle' => 'friendly_analytics_embed_codes', 'field' => $code('Cookieless analytics', 'Always loaded (e.g. Fathom).')],
                ['handle' => 'verification_embed_codes', 'field' => $code('Verification codes', 'Always in the head.')],
            ]]]],
        ]];
    }

    // SEO of the home page / site defaults and of every listing page in seo.index_pages
    protected static function seo(): array
    {
        $tabs = [];
        $pages = ['home' => ['Home / defaults']] + Reps::config('seo.index_pages', []);

        foreach ($pages as $key => $page) {
            $tabs[$key] = [
                'display' => is_array($page) ? ($page[0] ?? ucfirst($key)) : (string) $page,
                'sections' => [['fields' => [['import' => 'reps::seo', 'prefix' => "{$key}_"]]]],
            ];
        }

        return ['tabs' => $tabs];
    }
}
