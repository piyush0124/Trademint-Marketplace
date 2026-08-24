<?php

/*
 * Temporary: validates each sitemap over HTTP and writes a short report to a file.
 *
 * Writes to a file rather than stdout because the shell in use kept interleaving and
 * truncating large responses, which made it impossible to tell a real failure from a
 * capture artefact.
 */
$base = 'http://127.0.0.1:8013';
$report = [];

$paths = [
    '/robots.txt',
    '/sitemap.xml',
    '/sitemap-pages.xml',
    '/sitemap-listings.xml',
    '/sitemap-businesses.xml',
    '/sitemap-categories.xml',
];

foreach ($paths as $path) {
    $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 30]]);
    $body = @file_get_contents($base.$path, false, $context);
    $status = 'no-response';

    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $header) {
            if (str_starts_with($header, 'HTTP/')) {
                $status = trim(str_replace(['HTTP/1.1', 'HTTP/1.0'], '', $header));
            }
        }
    }

    if ($body === false) {
        $report[] = sprintf('%-26s FETCH FAILED', $path);

        continue;
    }

    $length = strlen($body);
    $firstBytes = substr(ltrim($body), 0, 40);

    if ($path === '/robots.txt') {
        $report[] = sprintf('%-26s %s  bytes=%d  hasSitemap=%s', $path, $status, $length,
            str_contains($body, 'Sitemap:') ? 'yes' : 'NO');

        continue;
    }

    // Is this even XML, or did the request fall through to an HTML page?
    if (! str_starts_with(ltrim($body), '<?xml')) {
        $report[] = sprintf('%-26s %s  NOT XML — begins: %s', $path, $status, str_replace("\n", ' ', $firstBytes));

        continue;
    }

    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($body);
    $errors = libxml_get_errors();
    libxml_clear_errors();

    if ($xml === false) {
        $report[] = sprintf('%-26s %s  INVALID XML: %s', $path, $status, trim($errors[0]->message ?? '?'));

        continue;
    }

    $first = (string) ($xml->url[0]->loc ?? $xml->sitemap[0]->loc ?? '');
    $report[] = sprintf('%-26s %s  root=<%s> entries=%d', $path, $status, $xml->getName(), $xml->count());
    $report[] = sprintf('%-26s   first=%s', '', $first);
}

file_put_contents(__DIR__.'/seo_report.txt', implode("\n", $report)."\n");
echo "written\n";
