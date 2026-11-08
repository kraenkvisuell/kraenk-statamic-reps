<title>{{ $title }}</title>
@if ($description)
<meta name="description" content="{{ $description }}">
@endif
@if ($keywords)
<meta name="keywords" content="{{ $keywords }}">
@endif
<meta name="robots" content="{{ $robots }}">
@if ($canonical)
<link rel="canonical" href="{{ $canonical }}">
@endif
@foreach ($alternates as $alternate)
<link rel="alternate" hreflang="{{ $alternate['hreflang'] }}" href="{{ $alternate['url'] }}">
@endforeach
<meta property="og:type" content="{{ $og_type }}">
<meta property="og:site_name" content="{{ $site_name }}">
<meta property="og:locale" content="{{ str_replace('-', '_', $locale) }}">
<meta property="og:title" content="{{ $og_title }}">
@if ($og_description)
<meta property="og:description" content="{{ $og_description }}">
@endif
@if ($canonical)
<meta property="og:url" content="{{ $canonical }}">
@endif
@if ($og_image)
<meta property="og:image" content="{{ $og_image }}">
<meta name="twitter:card" content="summary_large_image">
@endif
