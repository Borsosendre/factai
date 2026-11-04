<?php
/** FACT.AI dashboard: login, news editor, users. Single entry point. */
declare(strict_types=1);

require __DIR__ . '/lib.php';

header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; frame-ancestors 'none'; form-action 'self'; base-uri 'none'");
header('Cache-Control: no-store');

sess_start();
$user = current_user();

function flash(string $type, string $text): void
{
    $_SESSION['flash'][] = [$type, $text];
}

function go(string $query = '')
{
    header('Location: ./' . ($query !== '' ? '?' . $query : ''));
    exit;
}

function arr_lang(string $key): array
{
    $out = [];
    foreach (LANGS as $l) {
        $out[$l] = trim((string)($_POST[$key][$l] ?? ''));
    }
    return $out;
}

function nonempty(array $a): bool
{
    return (bool)array_filter($a, fn($v) => $v !== '');
}

/* =============== POST actions =============== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $back = '';
    try {
        if (!csrf_ok()) {
            throw new RuntimeException('Lejárt az űrlap. Töltsd újra az oldalt, és próbáld újra.');
        }

        if ($action === 'login') {
            $name = strtolower(trim((string)($_POST['username'] ?? '')));
            if (rl_blocked($name)) {
                throw new RuntimeException('Túl sok sikertelen próbálkozás. Várj 15 percet, és próbáld újra.');
            }
            if (!check_credentials($name, (string)($_POST['password'] ?? ''))) {
                rl_fail($name);
                throw new RuntimeException('Hibás felhasználónév vagy jelszó.');
            }
            rl_clear($name);
            login_user($name);
            go();
        }

        if ($action === 'setup') {
            $code = (string)cfg('setup_code', '');
            if (users_load() || $code === '' || !hash_equals($code, (string)($_POST['setup_code'] ?? ''))) {
                throw new RuntimeException('A beállítási kód hibás, vagy már van felhasználó.');
            }
            $name = strtolower(trim((string)($_POST['username'] ?? '')));
            $pw = (string)($_POST['password'] ?? '');
            if (!valid_username($name)) {
                throw new RuntimeException('A felhasználónév 3-32 karakter, kisbetű, szám, pont, kötőjel vagy aláhúzás lehet.');
            }
            if ($p = password_problem($pw)) {
                throw new RuntimeException($p);
            }
            users_save([$name => ['hash' => password_hash($pw, PASSWORD_DEFAULT), 'role' => 'admin', 'label' => $name, 'created' => date('c')]]);
            login_user($name);
            flash('ok', 'Az első admin létrejött. Kérlek, töröld a beállítási kódot a config.php-ból.');
            go();
        }

        if (!$user) {
            go();
        }

        switch ($action) {
            case 'logout':
                $_SESSION = [];
                session_destroy();
                header('Location: ./');
                exit;

            case 'save_post':
                $id = (string)($_POST['id'] ?? '');
                $back = $id !== '' ? 'edit=' . rawurlencode($id) : 'new=1';
                $date = (string)($_POST['date'] ?? '');
                if (!DateTime::createFromFormat('Y-m-d', $date)) {
                    throw new RuntimeException('Adj meg érvényes dátumot.');
                }
                $title = arr_lang('title');
                $summary = arr_lang('summary');
                $body = arr_lang('body');
                $alt = arr_lang('alt');
                $label = arr_lang('link_label');
                if ($title['en'] === '' || $summary['en'] === '') {
                    throw new RuntimeException('Az angol cím és összefoglaló kötelező, a magyar és román hiányában ez jelenik meg.');
                }
                foreach (LANGS as $l) {
                    if (mb_strlen($title[$l]) > 140 || mb_strlen($summary[$l]) > 500 || mb_strlen($body[$l]) > 6000) {
                        throw new RuntimeException('Túl hosszú szöveg (cím 140, összefoglaló 500, részletes szöveg 6000 karakter).');
                    }
                }
                $url = trim((string)($_POST['link_url'] ?? ''));
                if ($url !== '' && !preg_match('#^https?://[^\s]+$#i', $url)) {
                    throw new RuntimeException('A hivatkozás http:// vagy https:// kezdetű legyen.');
                }
                $published = ($_POST['status'] ?? 'published') === 'published';
                $upload = $_FILES['image'] ?? null;
                $hasUpload = $upload && ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
                if ($hasUpload && empty($_POST['consent'])) {
                    throw new RuntimeException('Képfeltöltéshez jelöld be, hogy a képen szereplők hozzájárultak a közzétételhez.');
                }

                $saved = with_lock(function () use ($id, $date, $title, $summary, $body, $alt, $label, $url, $published, $hasUpload, $upload) {
                    $items = news_load();
                    $idx = null;
                    foreach ($items as $k => $it) {
                        if ($it['id'] === $id) {
                            $idx = $k;
                        }
                    }
                    if ($id !== '' && $idx === null) {
                        throw new RuntimeException('A hír nem található (közben törölte valaki).');
                    }
                    $item = $idx !== null ? $items[$idx] : ['id' => ''];
                    if ($idx === null) {
                        $base = slugify($title['en']) . '-' . $date;
                        $new = $base;
                        $ids = array_column($items, 'id');
                        for ($n = 2; in_array($new, $ids, true); $n++) {
                            $new = $base . '-' . $n;
                        }
                        $item['id'] = $new;
                    }
                    $item['date'] = $date;
                    $item['published'] = $published;
                    $item['title'] = $title;
                    $item['summary'] = $summary;
                    if (nonempty($body)) {
                        $item['body'] = $body;
                    } else {
                        unset($item['body']);
                    }
                    if ($url !== '') {
                        $item['link'] = ['url' => $url, 'label' => $label];
                    } else {
                        unset($item['link']);
                    }
                    $oldImage = basename((string)($item['image']['src'] ?? ''));
                    $keepsImage = $hasUpload || (!empty($item['image']) && empty($_POST['remove_image']));
                    if ($keepsImage && $alt['en'] === '') {
                        throw new RuntimeException('A képhez az angol képleírás (alt) kötelező, ez segíti a képernyőolvasót használókat.');
                    }
                    $newImage = '';
                    try {
                        if ($hasUpload) {
                            $newImage = image_store($upload);
                            $item['image'] = ['src' => $newImage, 'alt' => $alt];
                        } elseif (!empty($_POST['remove_image'])) {
                            unset($item['image']);
                        } elseif (!empty($item['image'])) {
                            $item['image']['alt'] = $alt;
                        }
                        if ($idx === null) {
                            array_unshift($items, $item);
                        } else {
                            $items[$idx] = $item;
                        }
                        news_save($items);
                        rerender_pages();
                    } catch (Throwable $e) {
                        if ($newImage !== '') {
                            @unlink(IMG_DIR . '/' . $newImage);
                        }
                        throw $e;
                    }
                    if ($oldImage !== '' && $oldImage !== basename((string)($item['image']['src'] ?? ''))) {
                        image_remove_if_unused($oldImage, $items);
                    }
                    return $item['id'];
                });
                flash('ok', $published ? 'Mentve, a hír megjelent az oldalon.' : 'Piszkozatként mentve, az oldalon nem látszik.');
                go();

            case 'delete_post':
                $id = (string)($_POST['id'] ?? '');
                with_lock(function () use ($id) {
                    $items = news_load();
                    $gone = null;
                    $keep = [];
                    foreach ($items as $it) {
                        if ($it['id'] === $id) {
                            $gone = $it;
                        } else {
                            $keep[] = $it;
                        }
                    }
                    if (!$gone) {
                        throw new RuntimeException('A hír nem található.');
                    }
                    news_save($keep);
                    rerender_pages();
                    if (!empty($gone['image']['src'])) {
                        image_remove_if_unused(basename($gone['image']['src']), $keep);
                    }
                });
                flash('ok', 'A hír törölve. A korábbi állapot az előzményekben megmaradt.');
                go();

            case 'add_user':
                $back = 'users=1';
                if ($user['role'] !== 'admin') {
                    throw new RuntimeException('Ehhez admin jog kell.');
                }
                $name = strtolower(trim((string)($_POST['username'] ?? '')));
                $pw = (string)($_POST['password'] ?? '');
                $role = ($_POST['role'] ?? '') === 'admin' ? 'admin' : 'editor';
                if (!valid_username($name)) {
                    throw new RuntimeException('A felhasználónév 3-32 karakter, kisbetű, szám, pont, kötőjel vagy aláhúzás lehet.');
                }
                if ($p = password_problem($pw)) {
                    throw new RuntimeException($p);
                }
                with_lock(function () use ($name, $pw, $role) {
                    $u = users_load();
                    if (isset($u[$name])) {
                        throw new RuntimeException('Ez a felhasználónév már foglalt.');
                    }
                    $u[$name] = ['hash' => password_hash($pw, PASSWORD_DEFAULT), 'role' => $role, 'label' => $name, 'created' => date('c')];
                    users_save($u);
                });
                flash('ok', "A(z) $name felhasználó létrejött. Add át neki a jelszót biztonságos csatornán.");
                go('users=1');

            case 'reset_user':
            case 'delete_user':
                $back = 'users=1';
                if ($user['role'] !== 'admin') {
                    throw new RuntimeException('Ehhez admin jog kell.');
                }
                $name = (string)($_POST['username'] ?? '');
                with_lock(function () use ($action, $name, $user) {
                    $u = users_load();
                    if (!isset($u[$name])) {
                        throw new RuntimeException('Nincs ilyen felhasználó.');
                    }
                    if ($action === 'delete_user') {
                        if ($name === $user['name']) {
                            throw new RuntimeException('Saját magadat nem törölheted.');
                        }
                        unset($u[$name]);
                    } else {
                        $pw = (string)($_POST['password'] ?? '');
                        if ($p = password_problem($pw)) {
                            throw new RuntimeException($p);
                        }
                        $u[$name]['hash'] = password_hash($pw, PASSWORD_DEFAULT);
                    }
                    users_save($u);
                });
                flash('ok', $action === 'delete_user' ? 'A felhasználó törölve.' : 'Az új jelszó beállítva.');
                go('users=1');

            case 'change_pw':
                $back = 'password=1';
                $users = users_load();
                if (!password_verify((string)($_POST['current'] ?? ''), $users[$user['name']]['hash'] ?? '')) {
                    throw new RuntimeException('A jelenlegi jelszó hibás.');
                }
                $pw = (string)($_POST['password'] ?? '');
                if ($pw !== (string)($_POST['confirm'] ?? '')) {
                    throw new RuntimeException('A két új jelszó nem egyezik.');
                }
                if ($p = password_problem($pw)) {
                    throw new RuntimeException($p);
                }
                with_lock(function () use ($user, $pw) {
                    $u = users_load();
                    $u[$user['name']]['hash'] = password_hash($pw, PASSWORD_DEFAULT);
                    users_save($u);
                });
                flash('ok', 'A jelszavad megváltozott.');
                go();
        }
    } catch (Throwable $e) {
        flash('err', $e instanceof RuntimeException ? $e->getMessage() : 'Váratlan hiba történt. Szólj a rendszergazdának.');
        if (!($e instanceof RuntimeException)) {
            error_log('FACT.AI admin: ' . $e);
        }
        go($back);
    }
    go();
}

/* =============== views =============== */

function page_start(string $title, ?array $user): void
{
    $flash = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    ?><!doctype html>
<html lang="hu">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="color-scheme" content="light dark">
<title><?= h($title) ?> | FACT.AI szerkesztő</title>
<link rel="icon" href="../assets/img/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="../assets/css/fonts.css">
<link rel="stylesheet" href="admin.css">
<script src="admin.js" defer></script>
</head>
<body>
<header class="bar">
  <div class="bar-in">
    <a class="brand" href="./"><picture><source media="(prefers-color-scheme: dark)" srcset="../assets/img/factai-logo-dark.svg"><img src="../assets/img/factai-logo-light.svg" alt="FACT.AI" width="130" height="32"></picture><span>szerkesztő</span></a>
<?php if ($user): ?>
    <nav aria-label="Menü">
      <a href="./">Hírek</a>
      <a href="./?new=1">Új hír</a>
      <?php if ($user['role'] === 'admin'): ?><a href="./?users=1">Felhasználók</a><?php endif; ?>
      <a href="./?password=1">Jelszó</a>
      <a href="../en/" target="_blank" rel="noopener">Az oldal megtekintése</a>
      <form method="post" class="inline"><input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="action" value="logout"><button class="link" type="submit">Kilépés (<?= h($user['name']) ?>)</button></form>
    </nav>
<?php endif; ?>
  </div>
</header>
<main class="wrap">
<?php foreach ($flash as [$type, $text]): ?>
  <p class="flash <?= $type === 'ok' ? 'ok' : 'err' ?>" role="<?= $type === 'ok' ? 'status' : 'alert' ?>"><?= h($text) ?></p>
<?php endforeach;
}

function page_end(): void
{
    echo "</main>\n</body>\n</html>\n";
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf()) . '">';
}

/* ---- not signed in ---- */
if (!$user) {
    $noUsers = !users_load();
    page_start('Belépés', null);
    if ($noUsers && cfg('setup_code') !== '') { ?>
<section class="card narrow">
  <h1>Első beállítás</h1>
  <p>Még nincs felhasználó. Add meg a beállítási kódot (a config.php-ban van), és hozd létre az első admint.</p>
  <form method="post" autocomplete="off">
    <?= csrf_field() ?><input type="hidden" name="action" value="setup">
    <label for="sc">Beállítási kód</label><input id="sc" name="setup_code" type="password" required>
    <label for="su">Felhasználónév</label><input id="su" name="username" required pattern="[a-z0-9][a-z0-9._-]{2,31}" autocapitalize="none">
    <label for="sp">Jelszó (legalább <?= (int)cfg('min_password', 12) ?> karakter)</label><input id="sp" name="password" type="password" required minlength="<?= (int)cfg('min_password', 12) ?>" autocomplete="new-password">
    <button class="btn" type="submit">Admin létrehozása</button>
  </form>
</section>
<?php } elseif ($noUsers) { ?>
<section class="card narrow">
  <h1>Még nincs felhasználó</h1>
  <p>A rendszergazda hozza létre az első admint a szerveren: <code>php admin/create-user.php felhasznalonev admin</code>, vagy a config.php-ban megad egy beállítási kódot (<code>setup_code</code>).</p>
</section>
<?php } else { ?>
<section class="card narrow">
  <h1>Belépés</h1>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="login">
    <label for="lu">Felhasználónév</label><input id="lu" name="username" required autocomplete="username" autocapitalize="none" autofocus>
    <label for="lp">Jelszó</label><input id="lp" name="password" type="password" required autocomplete="current-password">
    <button class="btn" type="submit">Belépés</button>
  </form>
</section>
<?php }
    page_end();
    exit;
}

/* ---- signed in ---- */
$items = news_load();

if (isset($_GET['users']) && $user['role'] === 'admin') {
    $all = users_load();
    page_start('Felhasználók', $user); ?>
<h1>Felhasználók</h1>
<div class="grid2">
<section class="card">
  <h2>Új felhasználó</h2>
  <form method="post" autocomplete="off">
    <?= csrf_field() ?><input type="hidden" name="action" value="add_user">
    <label for="nu">Felhasználónév</label><input id="nu" name="username" required pattern="[a-z0-9][a-z0-9._-]{2,31}" autocapitalize="none">
    <label for="np">Jelszó</label>
    <div class="row"><input id="np" name="password" type="text" required minlength="<?= (int)cfg('min_password', 12) ?>" autocomplete="off"><button class="btn ghost" type="button" data-genpw="np">Generálás</button></div>
    <label for="nr">Szerepkör</label>
    <select id="nr" name="role"><option value="editor">Szerkesztő (híreket szerkeszt)</option><option value="admin">Admin (felhasználókat is kezel)</option></select>
    <button class="btn" type="submit">Felhasználó létrehozása</button>
  </form>
</section>
<section class="card">
  <h2>Meglévő felhasználók</h2>
  <ul class="users">
<?php foreach ($all as $name => $u): ?>
    <li>
      <div><strong><?= h($name) ?></strong> <span class="badge"><?= $u['role'] === 'admin' ? 'admin' : 'szerkesztő' ?></span></div>
      <form method="post" class="row" autocomplete="off">
        <?= csrf_field() ?><input type="hidden" name="action" value="reset_user"><input type="hidden" name="username" value="<?= h($name) ?>">
        <input type="text" name="password" placeholder="Új jelszó" required minlength="<?= (int)cfg('min_password', 12) ?>" aria-label="Új jelszó: <?= h($name) ?>" autocomplete="off">
        <button class="btn ghost small" type="submit">Jelszó csere</button>
      </form>
<?php if ($name !== $user['name']): ?>
      <form method="post" data-confirm="Biztosan törlöd a(z) <?= h($name) ?> felhasználót?">
        <?= csrf_field() ?><input type="hidden" name="action" value="delete_user"><input type="hidden" name="username" value="<?= h($name) ?>">
        <button class="btn danger small" type="submit">Törlés</button>
      </form>
<?php endif; ?>
    </li>
<?php endforeach; ?>
  </ul>
</section>
</div>
<?php page_end();
    exit;
}

if (isset($_GET['password'])) {
    page_start('Jelszó', $user); ?>
<section class="card narrow">
  <h1>Jelszó módosítása</h1>
  <form method="post" autocomplete="off">
    <?= csrf_field() ?><input type="hidden" name="action" value="change_pw">
    <label for="cp">Jelenlegi jelszó</label><input id="cp" name="current" type="password" required autocomplete="current-password">
    <label for="n1">Új jelszó (legalább <?= (int)cfg('min_password', 12) ?> karakter)</label><input id="n1" name="password" type="password" required minlength="<?= (int)cfg('min_password', 12) ?>" autocomplete="new-password">
    <label for="n2">Új jelszó még egyszer</label><input id="n2" name="confirm" type="password" required autocomplete="new-password">
    <button class="btn" type="submit">Mentés</button>
  </form>
</section>
<?php page_end();
    exit;
}

if (isset($_GET['new']) || isset($_GET['edit'])) {
    $editId = (string)($_GET['edit'] ?? '');
    $it = null;
    foreach ($items as $x) {
        if ($x['id'] === $editId) {
            $it = $x;
        }
    }
    if ($editId !== '' && !$it) {
        flash('err', 'A hír nem található.');
        go();
    }
    $v = fn(string $k, string $l) => h((string)($it[$k][$l] ?? ''));
    $names = ['en' => 'English', 'hu' => 'Magyar', 'ro' => 'Română'];
    page_start($it ? 'Hír szerkesztése' : 'Új hír', $user); ?>
<h1><?= $it ? 'Hír szerkesztése' : 'Új hír' ?></h1>
<form method="post" enctype="multipart/form-data" class="card editor">
  <?= csrf_field() ?><input type="hidden" name="action" value="save_post"><input type="hidden" name="id" value="<?= h($it['id'] ?? '') ?>">
  <div class="row wrap-row">
    <div><label for="date">Dátum</label><input id="date" type="date" name="date" required value="<?= h($it['date'] ?? date('Y-m-d')) ?>"></div>
    <div><label for="status">Állapot</label>
      <select id="status" name="status">
        <option value="published"<?= ($it['published'] ?? true) !== false ? ' selected' : '' ?>>Közzétéve</option>
        <option value="draft"<?= ($it['published'] ?? true) === false ? ' selected' : '' ?>>Piszkozat (nem látszik)</option>
      </select></div>
  </div>

  <div class="tabs" role="tablist" aria-label="Nyelv">
<?php foreach ($names as $l => $n): ?>
    <button type="button" role="tab" data-tab="<?= $l ?>" aria-selected="<?= $l === 'en' ? 'true' : 'false' ?>"><?= $n ?><?= $l === 'en' ? ' *' : '' ?></button>
<?php endforeach; ?>
  </div>
<?php foreach ($names as $l => $n): ?>
  <fieldset class="lang" data-lang="<?= $l ?>">
    <legend><?= $n ?><?= $l === 'en' ? ' (kötelező)' : ' (üresen az angol jelenik meg)' ?></legend>
    <label for="t-<?= $l ?>">Cím (legfeljebb 140 karakter)</label>
    <input id="t-<?= $l ?>" name="title[<?= $l ?>]" maxlength="140" value="<?= $v('title', $l) ?>" <?= $l === 'en' ? 'required' : '' ?>>
    <label for="s-<?= $l ?>">Összefoglaló (1-2 mondat, legfeljebb 500 karakter)</label>
    <textarea id="s-<?= $l ?>" name="summary[<?= $l ?>]" rows="3" maxlength="500" <?= $l === 'en' ? 'required' : '' ?>><?= $v('summary', $l) ?></textarea>
    <label for="b-<?= $l ?>">Részletes szöveg (nem kötelező; üres sor új bekezdés)</label>
    <textarea id="b-<?= $l ?>" name="body[<?= $l ?>]" rows="6" maxlength="6000"><?= $v('body', $l) ?></textarea>
    <label for="ll-<?= $l ?>">Hivatkozás szövege (ha van hivatkozás)</label>
    <input id="ll-<?= $l ?>" name="link_label[<?= $l ?>]" maxlength="100" value="<?= h((string)($it['link']['label'][$l] ?? '')) ?>">
    <label for="al-<?= $l ?>">Képleírás (alt), a képernyőolvasóknak</label>
    <input id="al-<?= $l ?>" name="alt[<?= $l ?>]" maxlength="200" value="<?= h((string)($it['image']['alt'][$l] ?? '')) ?>">
  </fieldset>
<?php endforeach; ?>

  <fieldset>
    <legend>Hivatkozás</legend>
    <label for="lu2">Webcím (nem kötelező, https://...)</label>
    <input id="lu2" type="url" name="link_url" value="<?= h((string)($it['link']['url'] ?? '')) ?>">
  </fieldset>

  <fieldset>
    <legend>Kép</legend>
<?php if (!empty($it['image']['src'])): ?>
    <p><img class="thumb" src="../assets/img/news/<?= h(basename($it['image']['src'])) ?>" alt=""></p>
    <label class="check"><input type="checkbox" name="remove_image" value="1"> A jelenlegi kép eltávolítása</label>
<?php endif; ?>
    <label for="img"><?= !empty($it['image']['src']) ? 'Új kép (lecseréli a jelenlegit)' : 'Kép feltöltése (JPG, PNG vagy WebP, legfeljebb ' . (int)cfg('max_upload_mb') . ' MB)' ?></label>
    <input id="img" type="file" name="image" accept="image/jpeg,image/png,image/webp">
    <p class="hint">A kép automatikusan legfeljebb <?= (int)cfg('image_max_px') ?> px szélesre kicsinyítődik, és a rejtett adatai (például a helyadat) törlődnek. Az oldalon csak valódi résztvevői fotó legyen, gépi képet emberről ne tölts fel.</p>
    <label class="check"><input type="checkbox" name="consent" value="1"> A képen látható mindenkitől megvan a közzétételhez szükséges hozzájárulás.</label>
  </fieldset>

  <div class="row">
    <button class="btn" type="submit">Mentés</button>
    <a class="btn ghost" href="./">Mégse</a>
  </div>
</form>
<?php page_end();
    exit;
}

/* ---- list ---- */
usort($items, fn($a, $b) => strcmp($b['date'], $a['date']));
page_start('Hírek', $user); ?>
<div class="head-row"><h1>Hírek</h1><a class="btn" href="./?new=1">Új hír</a></div>
<?php if (!$items): ?>
<p class="card">Még nincs hír. Az „Új hír” gombbal kezdhetsz.</p>
<?php else: ?>
<ul class="posts">
<?php foreach ($items as $it): $draft = ($it['published'] ?? true) === false; ?>
  <li class="card post">
    <?php if (!empty($it['image']['src'])): ?><img class="thumb" src="../assets/img/news/<?= h(basename($it['image']['src'])) ?>" alt=""><?php endif; ?>
    <div class="post-main">
      <div class="meta"><time datetime="<?= h($it['date']) ?>"><?= h($it['date']) ?></time> <span class="badge<?= $draft ? ' draft' : '' ?>"><?= $draft ? 'piszkozat' : 'közzétéve' ?></span>
        <?php foreach (LANGS as $l): ?><span class="lg<?= trim((string)($it['title'][$l] ?? '')) === '' ? ' off' : '' ?>"><?= strtoupper($l) ?></span><?php endforeach; ?></div>
      <strong><?= h(tr($it['title'] ?? '', 'en')) ?></strong>
    </div>
    <div class="actions">
      <a class="btn ghost small" href="./?edit=<?= h(rawurlencode($it['id'])) ?>">Szerkesztés</a>
      <form method="post" data-confirm="Biztosan törlöd ezt a hírt?">
        <?= csrf_field() ?><input type="hidden" name="action" value="delete_post"><input type="hidden" name="id" value="<?= h($it['id']) ?>">
        <button class="btn danger small" type="submit">Törlés</button>
      </form>
    </div>
  </li>
<?php endforeach; ?>
</ul>
<?php endif;
page_end();
