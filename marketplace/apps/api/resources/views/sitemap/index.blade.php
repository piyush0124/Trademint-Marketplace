{{--
    Sitemap index.

    The `<?xml ... ?>` declaration is NOT emitted here. It is prepended by
    SitemapController::xml(), because the `?>` inside a Blade `@php echo '<?xml …?>'`
    line mis-compiles — Blade's own compiled output ends up terminated early and the
    view dies with "syntax error, unexpected identifier". Keeping the declaration in
    PHP removes the whole class of problem and stops it being duplicated in two views.

    Every value is escaped by `{{ }}`: business slugs and city names originate from
    user input, and a single bare ampersand makes the document unparseable — which
    fails silently, because a crawler just drops a malformed sitemap.
--}}
<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
@foreach ($sitemaps as $sitemap)
    <sitemap>
        <loc>{{ $sitemap['loc'] }}</loc>
@if (! empty($sitemap['lastmod']))
        <lastmod>{{ $sitemap['lastmod'] }}</lastmod>
@endif
    </sitemap>
@endforeach
</sitemapindex>
