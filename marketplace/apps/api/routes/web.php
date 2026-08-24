<?php

use App\Http\Controllers\SitemapController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Crawler-facing routes
|--------------------------------------------------------------------------
|
| Sitemaps and robots.txt are generated from the database rather than shipped as
| static files, for two reasons:
|
|  1. A static sitemap goes stale the moment a listing is published. These are the
|     pages whose entire value is being findable, so a sitemap that lags by a
|     deploy cycle defeats the point.
|
|  2. A static file has to hardcode the domain. On staging that means advertising
|     the production sitemap, or worse, inviting a crawler to index preview
|     content. `url()` resolves to whichever host is actually serving.
|
| Deliberately unauthenticated and outside the `api` prefix: crawlers do not carry
| tokens, and these paths are conventional at the site root.
|
| NOTE: the previous `public/robots.txt` was removed. A file in `public/` is served
| by the web server before Laravel's router runs, so it would have shadowed the
| route below and continued serving `Disallow:` with no sitemap reference.
*/
Route::get('/robots.txt', [SitemapController::class, 'robots']);

Route::get('/sitemap.xml', [SitemapController::class, 'index']);
Route::get('/sitemap-pages.xml', [SitemapController::class, 'pages']);
Route::get('/sitemap-listings.xml', [SitemapController::class, 'listings']);
Route::get('/sitemap-businesses.xml', [SitemapController::class, 'businesses']);
Route::get('/sitemap-categories.xml', [SitemapController::class, 'categories']);

Route::get('/', function () {
    return view('welcome');
});
