{{--
    A urlset.

    The `<?xml ... ?>` declaration is prepended by SitemapController::xml() rather
    than written here — see the note in sitemap/index.blade.php for why the Blade
    `@php echo '<?xml …?>'` form is unsafe.

    Every value is escaped by `{{ }}`. Listing slugs and city names come from user
    data, and one unescaped ampersand invalidates the whole document.
--}}
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
@foreach ($urls as $url)
    <url>
        <loc>{{ $url['loc'] }}</loc>
@if (! empty($url['lastmod']))
        <lastmod>{{ $url['lastmod'] }}</lastmod>
@endif
@if (! empty($url['changefreq']))
        <changefreq>{{ $url['changefreq'] }}</changefreq>
@endif
@if (! empty($url['priority']))
        <priority>{{ $url['priority'] }}</priority>
@endif
    </url>
@endforeach
</urlset>
