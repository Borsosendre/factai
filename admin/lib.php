<?php
/**
 * FACT.AI admin: shared library (storage, auth, images, news rendering).
 * Requires PHP 8.1+ with the gd and mbstring extensions.
 */
declare(strict_types=1);

const LANGS = ['en', 'hu', 'ro'];
define('SITE_ROOT', dirname(__DIR__));
define('NEWS_FILE', SITE_ROOT . '/content/news.json');
define('SITE_JSON', SITE_ROOT . '/content/site.json');
define('IMG_DIR', SITE_ROOT . '/assets/img/news');

/* ---------- config ---------- */

function cfg(string $key, $default = null)
{
    static $c = null;
    if ($c === null) {
        $c = [
            'data_dir' => SITE_ROOT . '/_data',   // best placed OUTSIDE the web root, see DEPLOY.md
            'setup_code' => '',                   // non-empty enables the first-admin form
            'max_upload_mb' => 8,
            'image_max_px' => 1600,
            'session_hours' => 8,
            'min_password' => 12,
        ];
        $f = __DIR__ . '/config.php';
        if (is_file($f)) {
            $u = require $f;
            if (is_array($u)) {
                $c = array_merge($c, $u);
            }
        }
    }
    return $c[$key] ?? $default;
}

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/* ---------- files ---------- */

function data_dir(): string
{
    $d = rtrim((string)cfg('data_dir'), '/');
    if (!is_dir($d)) {
        mkdir($d, 0750, true);
    }
    if (!is_file($d . '/.htaccess')) {
        @file_put_contents($d . '/.htaccess', "Require all denied\nDeny from all\n");
    }
    return $d;
}

function atomic_write(string $path, string $data, int $mode = 0644): void
{
    $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (file_put_contents($tmp, $data, LOCK_EX) === false) {
        throw new RuntimeException('Nem sikerült írni: ' . basename($path));
    }
    @chmod($tmp, $mode);
    if (!rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Nem sikerült menteni: ' . basename($path));
    }
}

/** Run $fn while holding the global write lock (serialises saves from several editors). */
function with_lock(callable $fn)
{
    $fh = fopen(data_dir() . '/.lock', 'c');
    flock($fh, LOCK_EX);
    try {
        return $fn();
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

function json_pretty($v): string
{
    return json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
}

/* ---------- users ---------- */

function users_load(): array
{
    $f = data_dir() . '/users.json';
    if (!is_file($f)) {
        return [];
    }
    $j = json_decode((string)file_get_contents($f), true);
    return is_array($j) ? $j : [];
}

function users_save(array $u): void
{
    atomic_write(data_dir() . '/users.json', json_pretty($u), 0640);
}

function valid_username(string $u): bool
{
    return (bool)preg_match('/^[a-z0-9][a-z0-9._-]{2,31}$/', $u);
}

function password_problem(string $p): ?string
{
    $min = (int)cfg('min_password', 12);
    if (mb_strlen($p) < $min) {
        return "A jelszó legalább $min karakter legyen.";
    }
    return null;
}

/* ---------- login throttling ---------- */

function rl_keys(string $user): array
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'cli';
    return ['ip:' . hash('sha256', $ip), 'u:' . hash('sha256', strtolower($user) . '|' . $ip)];
}

function rl_load(): array
{
    $f = data_dir() . '/attempts.json';
    $j = is_file($f) ? json_decode((string)file_get_contents($f), true) : [];
    $now = time();
    $out = [];
    foreach ((array)$j as $k => $times) {
        $t = array_values(array_filter((array)$times, fn($x) => $x > $now - 900));
        if ($t) {
            $out[$k] = $t;
        }
    }
    return $out;
}

function rl_blocked(string $user): bool
{
    $a = rl_load();
    [$kip, $ku] = rl_keys($user);
    return count($a[$kip] ?? []) >= 20 || count($a[$ku] ?? []) >= 5;
}

function rl_fail(string $user): void
{
    with_lock(function () use ($user) {
        $a = rl_load();
        foreach (rl_keys($user) as $k) {
            $a[$k][] = time();
        }
        atomic_write(data_dir() . '/attempts.json', json_encode($a), 0640);
    });
}

function rl_clear(string $user): void
{
    with_lock(function () use ($user) {
        $a = rl_load();
        unset($a[rl_keys($user)[1]]);
        atomic_write(data_dir() . '/attempts.json', json_encode($a), 0640);
    });
}

/* ---------- session / CSRF ---------- */

function sess_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_name('fa_admin');
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/admin/index.php'), '/') . '/',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

function csrf(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['csrf'];
}

function csrf_ok(): bool
{
    return !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''));
}

function current_user(): ?array
{
    if (empty($_SESSION['u'])) {
        return null;
    }
    if (time() - (int)($_SESSION['last'] ?? 0) > (int)cfg('session_hours', 8) * 3600) {
        $_SESSION = [];
        return null;
    }
    $users = users_load();
    $name = $_SESSION['u'];
    if (!isset($users[$name])) {
        $_SESSION = [];
        return null;
    }
    $_SESSION['last'] = time();
    return ['name' => $name, 'role' => $users[$name]['role'] ?? 'editor', 'label' => $users[$name]['label'] ?? $name];
}

function login_user(string $name): void
{
    session_regenerate_id(true);
    $_SESSION['u'] = $name;
    $_SESSION['last'] = time();
    $_SESSION['csrf'] = bin2hex(random_bytes(24));
}

/** Verify credentials. Always runs one password_verify so timing does not reveal valid names. */
function check_credentials(string $name, string $pass): bool
{
    static $dummy = null;
    if ($dummy === null) {
        $dummy = password_hash('not-a-real-password', PASSWORD_DEFAULT);
    }
    $users = users_load();
    $hash = $users[$name]['hash'] ?? $dummy;
    $ok = password_verify($pass, $hash);
    return $ok && isset($users[$name]);
}

/* ---------- site texts and news data ---------- */

function site_strings(string $lang): array
{
    static $j = null;
    if ($j === null) {
        $j = is_file(SITE_JSON) ? (json_decode((string)file_get_contents(SITE_JSON), true) ?: []) : [];
    }
    $t = $j[$lang] ?? [];
    return $t + ['news_read' => 'Read more', 'news_more' => 'Older news', 'news_empty' => 'No news yet.'];
}

function news_load(): array
{
    if (!is_file(NEWS_FILE)) {
        return [];
    }
    $j = json_decode((string)file_get_contents(NEWS_FILE), true);
    return is_array($j['items'] ?? null) ? $j['items'] : [];
}

function news_save(array $items): void
{
    $dir = data_dir() . '/history';
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
    if (is_file(NEWS_FILE)) {
        copy(NEWS_FILE, $dir . '/news-' . date('Ymd-His') . '.json');
        $old = glob($dir . '/news-*.json') ?: [];
        sort($old);
        foreach (array_slice($old, 0, max(0, count($old) - 50)) as $f) {
            @unlink($f);
        }
    }
    $help = 'Edited through the dashboard (/admin). Newest first is not required: the page sorts by date.';
    atomic_write(NEWS_FILE, json_pretty(['_help' => $help, 'items' => array_values($items)]));
}

/* ---------- rendering (must match build.py) ---------- */

function tr($obj, string $lang): string
{
    if (is_array($obj)) {
        $v = (string)($obj[$lang] ?? '');
        return $v !== '' ? $v : (string)($obj['en'] ?? '');
    }
    return (string)$obj;
}

function fmt_date(string $iso, string $lang): string
{
    static $m = [
        'en' => ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
        'hu' => ['jan.', 'febr.', 'márc.', 'ápr.', 'máj.', 'jún.', 'júl.', 'aug.', 'szept.', 'okt.', 'nov.', 'dec.'],
        'ro' => ['ian.', 'feb.', 'mar.', 'apr.', 'mai', 'iun.', 'iul.', 'aug.', 'sept.', 'oct.', 'nov.', 'dec.'],
    ];
    $d = DateTime::createFromFormat('Y-m-d', $iso);
    if (!$d) {
        return $iso;
    }
    $mon = $m[$lang][(int)$d->format('n') - 1];
    $day = (int)$d->format('j');
    $y = $d->format('Y');
    return $lang === 'hu' ? "$y. $mon $day." : "$day $mon $y";
}

function paragraphs(string $text): string
{
    $out = '';
    foreach (preg_split('/\n\s*\n/', str_replace("\r\n", "\n", $text)) as $p) {
        if (trim($p) !== '') {
            $out .= '<p>' . h(trim($p)) . '</p>';
        }
    }
    return $out;
}

function news_item_html(array $it, string $lang, array $t): string
{
    $o = '<article class="news-item" id="news-' . h($it['id']) . '">'
        . '<time datetime="' . h($it['date']) . '">' . h(fmt_date($it['date'], $lang)) . '</time><div class="news-body">'
        . '<h3>' . h(tr($it['title'] ?? '', $lang)) . '</h3><p>' . h(tr($it['summary'] ?? '', $lang)) . '</p>';
    if (!empty($it['quote'])) {
        $q = $it['quote'];
        $o .= '<blockquote class="human"><p>' . h(tr($q['text'] ?? '', $lang)) . '</p><footer>' . h($q['who'] ?? '') . '</footer></blockquote>';
    }
    if (!empty($it['image']['src'])) {
        $o .= '<figure><img src="../assets/img/news/' . h(basename($it['image']['src'])) . '" alt="'
            . h(tr($it['image']['alt'] ?? '', $lang)) . '" loading="lazy"></figure>';
    }
    if (!empty($it['body'])) {
        $b = tr($it['body'], $lang);
        if (trim($b) !== '') {
            $o .= '<details class="more"><summary>' . h($t['news_read']) . '</summary>' . paragraphs($b) . '</details>';
        }
    }
    if (!empty($it['link']['url'])) {
        $o .= '<p><a class="textlink" href="' . h($it['link']['url']) . '" rel="noopener">'
            . h(tr($it['link']['label'] ?? '', $lang) ?: $it['link']['url']) . '</a></p>';
    }
    return $o . '</div></article>';
}

function news_block_html(string $lang): string
{
    $t = site_strings($lang);
    $items = array_values(array_filter(news_load(), fn($i) => ($i['published'] ?? true) !== false));
    usort($items, fn($a, $b) => strcmp($b['date'], $a['date'])); // stable since PHP 8.0
    $shown = array_slice($items, 0, 5);
    $older = array_slice($items, 5);
    $html = '';
    foreach ($shown as $i) {
        $html .= news_item_html($i, $lang, $t);
    }
    if ($html === '') {
        $html = '<p class="muted">' . h($t['news_empty']) . '</p>';
    }
    if ($older) {
        $html .= '<details class="older"><summary>' . h($t['news_more']) . ' (' . count($older) . ')</summary>';
        foreach ($older as $i) {
            $html .= news_item_html($i, $lang, $t);
        }
        $html .= '</details>';
    }
    return $html;
}

/** Rewrite the news block in en/hu/ro index.html. */
function rerender_pages(): void
{
    foreach (LANGS as $lang) {
        $f = SITE_ROOT . "/$lang/index.html";
        $src = (string)file_get_contents($f);
        $new = preg_replace_callback(
            '/<!--NEWS-->.*?<!--\/NEWS-->/s',
            fn() => '<!--NEWS-->' . news_block_html($lang) . '<!--/NEWS-->',
            $src,
            1,
            $n
        );
        if ($n !== 1) {
            throw new RuntimeException("Hiányzik a hír-jelölő: $lang/index.html");
        }
        atomic_write($f, $new);
    }
}

/* ---------- images ---------- */

function image_file_ok(string $name): bool
{
    return (bool)preg_match('/^[A-Za-z0-9._-]+\.(jpg|jpeg|png|webp)$/', $name);
}

/** Validate, re-encode (strips metadata such as GPS) and store an uploaded photo. Returns the file name. */
function image_store(array $f): string
{
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('A kép feltöltése nem sikerült (hibakód ' . ($f['error'] ?? '?') . ').');
    }
    $maxBytes = (int)cfg('max_upload_mb', 8) * 1048576;
    if ($f['size'] > $maxBytes) {
        throw new RuntimeException('A kép túl nagy (legfeljebb ' . cfg('max_upload_mb') . ' MB).');
    }
    if (!is_uploaded_file($f['tmp_name']) && PHP_SAPI !== 'cli') {
        throw new RuntimeException('Érvénytelen feltöltés.');
    }
    $info = @getimagesize($f['tmp_name']);
    $mime = $info['mime'] ?? '';
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        throw new RuntimeException('Csak JPG, PNG vagy WebP kép tölthető fel.');
    }
    if ($info[0] * $info[1] > 50_000_000) {
        throw new RuntimeException('A kép felbontása túl nagy.');
    }
    if ($mime === 'image/jpeg') {
        $img = @imagecreatefromjpeg($f['tmp_name']);
    } elseif ($mime === 'image/png') {
        $img = @imagecreatefrompng($f['tmp_name']);
    } else {
        $img = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($f['tmp_name']) : false;
    }
    if (!$img) {
        throw new RuntimeException('A kép nem olvasható.');
    }
    if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
        $ex = @exif_read_data($f['tmp_name']);
        $rot = [3 => 180, 6 => -90, 8 => 90][$ex['Orientation'] ?? 1] ?? 0;
        if ($rot) {
            $img = imagerotate($img, $rot, 0) ?: $img;
        }
    }
    $w = imagesx($img);
    $hgt = imagesy($img);
    $max = (int)cfg('image_max_px', 1600);
    if ($w > $max) {
        $img = imagescale($img, $max, (int)round($hgt * $max / $w), IMG_BICUBIC) ?: $img;
    }
    // flatten transparency on white, then write a fresh JPEG
    $flat = imagecreatetruecolor(imagesx($img), imagesy($img));
    imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
    imagecopy($flat, $img, 0, 0, 0, 0, imagesx($img), imagesy($img));
    if (!is_dir(IMG_DIR)) {
        mkdir(IMG_DIR, 0755, true);
    }
    $name = date('Ymd') . '-' . bin2hex(random_bytes(4)) . '.jpg';
    if (!imagejpeg($flat, IMG_DIR . '/' . $name, 82)) {
        throw new RuntimeException('A kép mentése nem sikerült.');
    }
    @chmod(IMG_DIR . '/' . $name, 0644);
    return $name;
}

function image_remove_if_unused(string $name, array $items): void
{
    if (!image_file_ok($name)) {
        return;
    }
    foreach ($items as $i) {
        if (basename((string)($i['image']['src'] ?? '')) === $name) {
            return;
        }
    }
    @unlink(IMG_DIR . '/' . $name);
}

function slugify(string $s): string
{
    $s = strtolower(trim($s));
    $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim(substr($s, 0, 48), '-') ?: 'news';
}
