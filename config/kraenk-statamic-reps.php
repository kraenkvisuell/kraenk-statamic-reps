<?php

// Shared settings of the artist representation sites. Every key has a default here; a site's
// config/kraenk-statamic-reps.php lists only what differs (it is merged recursively over this file).

return [

    /*
    | Optional content types. Each switches a collection (and its routes, blueprints, import and
    | sitemap entries) on or off.
    */
    'collections' => [
        'news' => false,                // news items / blog posts
        'productions' => false,         // production portfolio (visualeyes)
        'special_categories' => false,  // special categories with their projects (krw)
        'projects' => false,            // concept projects + skills (krw "Konzept")
        'clients' => false,             // client logos (krw)
    ],

    // collections that reps:install leaves without an order (no tree, the CP lists them as a list),
    // e.g. ['pages'] where the menus come from the navigations and the order shows nowhere
    'unordered_collections' => [],

    /*
    | Optional fields. A field tagged `reps_feature: <key>` in the addon's blueprints is only shown
    | when its feature is on.
    */
    'features' => [
        'external_artists' => false,       // artists without a portfolio page
        'artist_bgcolor' => false,         // a colour per artist
        'discipline_bgcolor' => false,     // a colour per discipline (wfr)
        'artist_testimonials' => false,
        'artist_skills' => false,          // skill headline/text/images on artists (krw)
        'artist_categories_order' => false, // artists order their categories (visualeyes)
        'category_slideshow_order' => false, // the category's slideshows, sortable in its form (krw)
        'portfolio_images' => false,       // works flagged as portfolio/megamenu images
        'startpage_images' => false,       // works flagged as start page images
        'discipline_images' => false,      // works flagged as discipline images
        'overview' => true,                // works flagged "show in overview"
        'overview_categories' => true,     // works flagged per category
        'represents_in_categories' => true, // works chosen to represent the artist in a category
        'captions' => false,
        // multi-site: translatable fields show all languages in the default site's form (multisite
        // fields); the other sites' entries only carry URL and values and aren't edited directly
        'translatable_fields' => true,
        // single site with bastihilger/simple-statamic-translations: localizable fields become
        // translated fields (Translations\SimpleTranslations); trial on kombinatrotweiss
        'simple_translations' => false,
        'work_translations' => false,      // English work texts on the row (title_en …; multi-site krw)
        'textboxes' => false,              // works that are text boxes (wfr)
        'work_bgcolor' => false,
        'width_in_overview' => false,      // regular / double (wfr)
        'width_in_frame' => false,         // full / two thirds / half (wfr)
        'title_position' => false,         // slideshow title position (wfr)
        'show_title' => false,
        'break_after' => false,            // break after a slideshow in overviews (wfr)
        'starts_right' => false,
        'slideshow_disciplines' => false,
        'page_bgcolor' => false,
        'lightbox' => false,               // visitors collect works into a PDF
        'lightbox_requires_login' => false, // only logged-in users see the lightbox (wfr)
        'news_subtitle' => false,
        'news_text' => false,              // a short rich text instead of content blocks (wfr)
        // the text on a news item in its own field (headline, the old title); the title is
        // generated as "Artist / Project" (Support\NewsTitle, the collection's title_format) (wfr)
        'news_headline' => false,
        'news_content' => true,            // content blocks (main_content)
        'news_articles' => false,          // date, abstract, overview image, sticky (krw)
        'news_post_types' => false,        // post / artist link / slideshow link, placeholder text (visualeyes)
        'news_link' => false,              // an own link instead of the artist / slideshow
        'artist_copy_links' => false,      // "Copy Link" buttons in the CP's artist list (a:<id> / b:<id>, krw)
    ],

    // Multi-site: redirect a 404 that is an entry's URL in another site (/en/<german slug>) to
    // the entry in the requested site, like the old sites that accepted every locale's slug.
    'localized_slug_redirects' => true,

    // CP labels (the old Nova sites renamed slideshows to "Projects" etc.)
    'labels' => [
        'artist' => 'Artist',
        'artists' => 'Artists',
        'slideshow' => 'Slideshow',
        'slideshows' => 'Slideshows',
        'work' => 'Work',
        'works' => 'Works',
        'category' => 'Category',
        'categories' => 'Categories',
        'discipline' => 'Discipline',
        'disciplines' => 'Disciplines',
        'news' => 'News',
        'portfolio_image' => 'Portfolio image',     // the works' flag ("Megamenu image" on visualeyes)
        'portfolio_images' => 'Portfolio images',   // column in the CP's artist list
        'startpage_image' => 'Start page image',
        'startpage_images' => 'Start page images',
    ],

    // CP artist list (like the old Nova index): portfolio images shown per artist
    'cp' => [
        'artist_portfolio_images' => 3,
        // fill the remaining slots when fewer works are flagged: works flagged show_in_overview, then
        // the first works (the old has_portfolio_images_fallback)
        'artist_portfolio_images_fallback' => false,
        // look for those works in the slideshows by their Nova id instead of in the artist's order
        // (the order the old index read them in; slideshows created since then come last)
        'artist_portfolio_images_legacy_order' => false,
        // videos in the portfolio and start page image columns play (muted, looped) instead of
        // showing a file icon, like the old Nova index (Fieldtypes\ListingAssets)
        'listing_videos' => false,
        'slideshow_thumbnails' => 99,      // works shown per slideshow in the artist's slideshows field
        // orderable collections whose CP listing starts with a position select (Cp\PositionColumn)
        'position_columns' => [],
    ],

    /*
    | Entry URLs per collection, as Statamic collection routes (null = no own pages). Use a string
    | for every site or an array keyed by site handle. Antlers works in routes:
    | '/projects/{artist_slug}/{slug}' ({artist_slug} / {special_category_slug} are computed).
    */
    'routes' => [
        'pages' => '/pages/{slug}',
        'artists' => '/artists/{slug}',
        'slideshows' => '/slideshows/{artist_slug}/{slug}',
        'categories' => null,
        'disciplines' => null,
        'news' => '/news/{slug}',
        'productions' => null,
        'special_categories' => '/special/{slug}',
        'special_projects' => '/special/{special_category_slug}/{slug}',
        'projects' => '/projects/{slug}',
        'clients' => null,
    ],

    // {route_segment} of category routes: per category slug, else the default
    'category_route_segments' => [],
    'category_route_segment' => 'category',

    // Slideshow URLs accept a trailing /{n} (the slide to start at), as on the old sites.
    'slideshow_index_segment' => true,

    // Content blocks editors can place on pages (and news / projects with main_content)
    'page_blocks' => ['text', 'text_images', 'image', 'gallery', 'video'],

    // Bard buttons of the rich text fields
    'bard_buttons' => ['h2', 'h3', 'bold', 'italic', 'anchor', 'unorderedlist', 'orderedlist', 'quote', 'removeformat'],

    'seo' => [
        // a filled browser title is the whole <title>; otherwise this format with {title} = the title
        'browser_title_verbatim' => true,
        // {title} = the entry's browser title or title, {site} = site_settings.website_title
        'title_format' => '{title} | {site}',
        // title of the home page and of pages without own title
        'home_title_format' => '{site}',
        'sitemap' => true,
        'canonical' => true,
        'hreflang' => true, // only rendered on multi-site installs
        /*
        | Listing pages that are not entries (routes in the site), with their own SEO fields in the
        | `seo` global: key => [label, url per site]. E.g. 'artists' => ['Artists', '/artists'].
        */
        'index_pages' => [],
        /*
        | og:image fallbacks per collection: the first filled field wins. `works:first` = the first
        | image work of a slideshow; dotted keys follow relations (portfolio_image of the artist).
        */
        'og_image_fallbacks' => [
            'artists' => ['og_image', 'portfolio_image', 'portrait_image'],
            'slideshows' => ['og_image', 'works:first'],
            'categories' => ['og_image', 'main_image'],
            'news' => ['og_image', 'image', 'overview_image'],
            'special_projects' => ['og_image', 'overview_image'],
            'projects' => ['og_image', 'overview_image'],
        ],
        // fallback when nothing else is set
        'default_og_image' => null,
        // collections listed in /sitemap.xml (only the enabled ones with a route are used)
        'sitemap_collections' => ['pages', 'artists', 'categories', 'disciplines', 'news', 'special_categories', 'special_projects', 'projects'],
    ],

    'lightbox' => [
        // Blade view of the site that renders the PDF ($groups: artist + works, $settings)
        'pdf_view' => 'lightbox.pdf',
        'paper' => 'a4',
        'orientation' => 'landscape',
    ],

    /*
    | Disks registered when the site doesn't define them itself: Bunny Storage (S3 API) for the
    | asset container and the Glide cache, and the old site's media bucket.
    */
    'disks' => [
        'bunny-assets' => [
            'driver' => 'bunny',
            'key' => env('BUNNY_S3_KEY'),
            'secret' => env('BUNNY_S3_SECRET'),
            'region' => env('BUNNY_S3_REGION', 'de'),
            'bucket' => env('BUNNY_S3_BUCKET'),
            'endpoint' => env('BUNNY_S3_ENDPOINT', 'https://de-s3.storage.bunnycdn.com'),
            'url' => env('BUNNY_PUBLIC_URL'),
            'root' => 'assets',
            'use_path_style_endpoint' => true,
            'throw' => false,
            'retries' => 3,         // failed reads, after 0.5 / 1 / 2 s (driver bunny from statamic-bunny-assets: S3 + retried reads)
            'retry_delay' => 500,
        ],
        'bunny-glide-cache' => [
            'driver' => 'bunny',
            'key' => env('BUNNY_S3_KEY'),
            'secret' => env('BUNNY_S3_SECRET'),
            'region' => env('BUNNY_S3_REGION', 'de'),
            'bucket' => env('BUNNY_S3_BUCKET'),
            'endpoint' => env('BUNNY_S3_ENDPOINT', 'https://de-s3.storage.bunnycdn.com'),
            'url' => env('BUNNY_PUBLIC_URL'),
            'root' => 'glide-cache',
            'use_path_style_endpoint' => true,
            'throw' => false,
            'retries' => 3,         // failed reads, after 0.5 / 1 / 2 s (driver bunny from statamic-bunny-assets: S3 + retried reads)
            'retry_delay' => 500,
        ],
        // the local mirror of the old originals and the container's disk until it moves to Bunny
        'assets' => [
            'driver' => 'local',
            'root' => 'public/assets', // relative to base_path()
            'url' => '/assets',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],
        // untouched copies of the old originals (rsync / download target of reps:import)
        'legacy-mirror' => [
            'driver' => 'local',
            'root' => 'storage/app/legacy-media',
            'throw' => false,
            'report' => false,
        ],
        'legacy-media' => env('LEGACY_S3_BUCKET') ? [
            'driver' => 's3',
            'key' => env('LEGACY_S3_KEY'),
            'secret' => env('LEGACY_S3_SECRET'),
            'region' => env('LEGACY_S3_REGION', 'auto'),
            'bucket' => env('LEGACY_S3_BUCKET'),
            'endpoint' => env('LEGACY_S3_ENDPOINT'),
            'root' => env('LEGACY_S3_ROOT', ''),
            'use_path_style_endpoint' => true,
            'throw' => false,
        ] : null,
    ],

    'imaging' => [
        // ImageMagick CLI for converting Adobe RGB originals to sRGB during the media sync
        'magick' => env('REPS_MAGICK_BIN', 'magick'),
    ],

    'assets' => [
        'container' => 'main',
        // disk of the container once Bunny credentials are set (reps:install writes `assets` until then)
        'cloud_disk' => 'bunny-assets',
    ],

    /*
    | Static cache warming. reps:warm (deploys, saves that change every page) queues on this queue, with its own job
    | (StaticCaching\DeployWarmJob); Statamic's static_caching.warm_queue then only carries the
    | re-caching after saves, which Horizon should work through first (e.g. queues
    | ['recache', 'default', 'warm'] without balancing). null: Statamic's warm_queue.
    */
    'static_caching' => [
        'warm_queue' => null,
        // The Bunny pull zone in front of the site: re-cached and invalidated pages are purged
        // there right away, the whole zone when the static cache is cleared (StaticCaching\BunnyPurge).
        // An account API key (Account settings → API key) and the pull zone's id. Empty: off.
        'bunny' => [
            'api_key' => env('BUNNY_API_KEY'),
            'pull_zone' => env('BUNNY_PULL_ZONE_ID'),
        ],
    ],

    /*
    | The old Nova site: database dump, media and server, read by reps:import and reps:resync.
    */
    'legacy' => [
        'connection' => [
            'driver' => 'mysql',
            'host' => env('LEGACY_DB_HOST', '127.0.0.1'),
            'port' => env('LEGACY_DB_PORT', '3306'),
            'database' => env('LEGACY_DB_DATABASE'),
            'username' => env('LEGACY_DB_USERNAME', 'root'),
            'password' => env('LEGACY_DB_PASSWORD', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => false,
        ],
        'mysql_bin' => env('LEGACY_MYSQL_BIN', '/Users/Shared/Herd/services/mysql/8.0.36/bin'),
        // prefix of the portfolio tables (pf_ on krw and wfr)
        'portfolio_prefix' => '',
        // the old site's locales, the first is the default; maps to Statamic site handles
        'locales' => ['en' => 'default'],
        // server of the live Nova site (ssh forge@host, path to the site's directory)
        'server' => null,
        'path' => null,
        // where the media originals are: 'server' (storage/app/public over rsync), 's3' (media_disk)
        // or 'http' (public URL of the originals, media_url)
        'media_source' => 'server',
        // for media_source s3: the disk (legacy-media is registered from LEGACY_S3_* env vars)
        'media_disk' => 'legacy-media',
        'media_url' => null,
        // media_source http: also compare the sizes of existing files (one HEAD request per file)
        'media_check_sizes' => false,
        // import the robots checkboxes (noindex / nofollow); false where the old theme ignored them
        // and wrote `index, follow` on every page: everything is imported as indexed and followed
        'robots' => true,
        // the old site's base URL, for links in content that point to itself
        'url' => null,
        // old URLs with Nova ids, redirected to the imported entry: pattern => collection
        // e.g. 'slideshows/{id}/{index?}' => 'slideshows'
        'id_redirects' => [],
        // the old site's page URL folder (/pages/slug), for links inside content
        'page_route' => 'pages',
        // nova_settings keys copied into globals: legacy key => [global handle, field handle]
        'settings' => [],
    ],
];
