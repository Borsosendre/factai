<?php
/**
 * Command line: rebuild the news block in en/hu/ro index.html from content/news.json.
 * Run after every deployment of new site files (deploy.sh does this).
 *   php admin/rerender.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/lib.php';

rerender_pages();
echo "A hírek újragenerálva (" . count(news_load()) . " hír).\n";
