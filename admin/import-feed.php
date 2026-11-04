<?php
/**
 * Command line: import news that Claude/Endre committed to the repository (content/news-feed.json
 * + feed-images/) into the live news list. Existing ids are NEVER overwritten, so edits made on the
 * dashboard win. Run by deploy.sh after every git pull.
 *   php admin/import-feed.php <repo-dir>
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/lib.php';

$repo = rtrim((string)($argv[1] ?? ''), '/');
$feed = $repo . '/content/news-feed.json';
if ($repo === '' || !is_file($feed)) {
    echo "Nincs feed fájl, nincs mit importálni.\n";
    exit(0);
}
$j = json_decode((string)file_get_contents($feed), true);
$new = is_array($j['items'] ?? null) ? $j['items'] : [];

$added = with_lock(function () use ($new, $repo) {
    $items = news_load();
    $have = [];
    foreach ($items as $it) {
        $have[$it['id'] ?? ''] = true;
    }
    $n = 0;
    foreach ($new as $it) {
        $id = (string)($it['id'] ?? '');
        if (!preg_match('/^[a-z0-9-]{1,80}$/', $id) || isset($have[$id])) {
            continue;
        }
        $img = basename((string)($it['image']['src'] ?? ''));
        if ($img !== '') {
            $src = $repo . '/feed-images/' . $img;
            if (!preg_match('/^[A-Za-z0-9._-]+\.(jpe?g|png|webp)$/i', $img) || !is_file($src)) {
                unset($it['image']);
            } else {
                if (!is_dir(IMG_DIR)) {
                    mkdir(IMG_DIR, 0755, true);
                }
                copy($src, IMG_DIR . '/' . $img);
            }
        }
        $items[] = $it;
        $n++;
    }
    if ($n > 0) {
        news_save($items);
    }
    return $n;
});
if ($added > 0) {
    rerender_pages();
}
echo "Importált hírek: $added\n";
