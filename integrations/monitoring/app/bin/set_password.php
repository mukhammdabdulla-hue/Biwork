<?php
declare(strict_types=1);
/** Foydalanuvchi yaratish yoki parolini almashtirish:  php bin/set_password.php <login> <parol> [admin|user] [Ism] */
require __DIR__ . '/../src/bootstrap.php';
[$self, $login, $pw] = array_pad($argv, 3, '');
$role = $argv[3] ?? 'admin';
$name = $argv[4] ?? $login;
if ($login === '' || strlen($pw) < 10 || !in_array($role, ['admin', 'user'], true)) {
    fwrite(STDERR, "Foydalanish: php bin/set_password.php <login> <parol (kamida 10 belgi)> [admin|user] [Ism]\n");
    exit(1);
}
Schema::ensure();
$u = Db::one('SELECT id FROM users WHERE login = ?', [$login]);
if ($u) {
    Db::exec('UPDATE users SET password_hash = ?, role = ?, active = 1 WHERE id = ?', [password_hash($pw, PASSWORD_DEFAULT), $role, $u['id']]);
    echo "Parol yangilandi: $login\n";
} else {
    Auth::createUser($login, $name, $role, $pw);
    echo "Foydalanuvchi yaratildi: $login ($role)\n";
}
