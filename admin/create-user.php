<?php
/**
 * Command line: create or reset a dashboard user.
 *   php admin/create-user.php <username> [admin|editor]
 * The password is asked on the terminal (input hidden).
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/lib.php';

$name = strtolower((string)($argv[1] ?? ''));
$role = ($argv[2] ?? 'editor') === 'admin' ? 'admin' : 'editor';
if (!valid_username($name)) {
    fwrite(STDERR, "Használat: php admin/create-user.php <felhasznalonev> [admin|editor]\nA felhasználónév 3-32 karakter: kisbetű, szám, . _ -\n");
    exit(1);
}
$tty = stripos(PHP_OS, 'WIN') === false;
echo "Jelszó (legalább " . cfg('min_password') . " karakter): ";
if ($tty) {
    shell_exec('stty -echo');
}
$pw = trim((string)fgets(STDIN));
if ($tty) {
    shell_exec('stty echo');
}
echo "\n";
if ($p = password_problem($pw)) {
    fwrite(STDERR, $p . "\n");
    exit(1);
}
$u = users_load();
$existed = isset($u[$name]);
$u[$name] = ['hash' => password_hash($pw, PASSWORD_DEFAULT), 'role' => $role, 'label' => $name, 'created' => $u[$name]['created'] ?? date('c')];
users_save($u);
echo ($existed ? "Felhasználó frissítve: " : "Felhasználó létrehozva: ") . "$name ($role)\n";
