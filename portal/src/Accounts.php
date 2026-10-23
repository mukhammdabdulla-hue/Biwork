<?php
declare(strict_types=1);

/** Korxonalar va foydalanuvchilar. */
final class Accounts
{
    /** Ro'yxatdan o'tish: korxona + egasi. Foydalanuvchi id sini qaytaradi. */
    public static function register(array $in, string $source = 'signup'): int
    {
        $company = trim((string)($in['company'] ?? ''));
        $name = trim((string)($in['name'] ?? ''));
        $email = mb_strtolower(trim((string)($in['email'] ?? '')));
        $phone = trim((string)($in['phone'] ?? ''));
        $pass = (string)($in['password'] ?? '');
        if ($company === '' || mb_strlen($company) > 120) {
            throw new HttpError(422, 'Korxona nomini kiriting');
        }
        if ($name === '' || mb_strlen($name) > 100) {
            throw new HttpError(422, 'Ismingizni kiriting');
        }
        if (!Util::emailOk($email)) {
            throw new HttpError(422, "Email noto'g'ri");
        }
        if (mb_strlen($phone) > 40) {
            throw new HttpError(422, 'Telefon raqami juda uzun');
        }
        if (($p = Auth::passwordProblem($pass, $email)) !== null) {
            throw new HttpError(422, $p);
        }
        if (Auth::byEmail($email)) {
            throw new HttpError(409, "Bu email bilan foydalanuvchi allaqachon bor. Kirishga urinib ko'ring");
        }
        return Db::tx(static function () use ($company, $name, $email, $phone, $pass, $source, $in): int {
            $cid = Db::insert('customers', [
                'name' => $company, 'stir' => mb_substr(trim((string)($in['stir'] ?? '')), 0, 20), 'city' => mb_substr(trim((string)($in['city'] ?? '')), 0, 60),
                'phone' => $phone, 'email' => $email, 'source' => $source, 'created_at' => Util::now(),
            ]);
            $uid = Db::insert('users', [
                'customer_id' => $cid, 'email' => $email, 'name' => $name, 'phone' => $phone,
                'password_hash' => password_hash($pass, PASSWORD_DEFAULT), 'role' => 'owner', 'created_at' => Util::now(),
            ]);
            Licenses::event(null, $cid, $source === 'signup' ? 'user:' . $uid : 'system', 'customer_created', $company);
            return $uid;
        });
    }

    public static function addMember(int $customerId, string $email, string $name, string $password, string $role = 'member', string $actor = 'system'): int
    {
        $email = mb_strtolower(trim($email));
        $name = trim($name);
        if (!Util::emailOk($email)) {
            throw new HttpError(422, "Email noto'g'ri");
        }
        if ($name === '' || mb_strlen($name) > 100) {
            throw new HttpError(422, 'Ismni kiriting');
        }
        if (($p = Auth::passwordProblem($password, $email)) !== null) {
            throw new HttpError(422, $p);
        }
        if (Auth::byEmail($email)) {
            throw new HttpError(409, 'Bu email bilan foydalanuvchi allaqachon bor');
        }
        $uid = Db::insert('users', [
            'customer_id' => $customerId, 'email' => $email, 'name' => $name,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'role' => $role === 'owner' ? 'owner' : 'member',
            'must_change_password' => 1, 'created_at' => Util::now(),
        ]);
        Licenses::event(null, $customerId, $actor, 'user_added', $email);
        return $uid;
    }

    public static function addStaff(string $email, string $name, string $password): int
    {
        $email = mb_strtolower(trim($email));
        if (!Util::emailOk($email)) {
            throw new HttpError(422, "Email noto'g'ri");
        }
        if (($p = Auth::passwordProblem($password, $email)) !== null) {
            throw new HttpError(422, $p);
        }
        if (Auth::byEmail($email)) {
            throw new HttpError(409, 'Bu email bilan foydalanuvchi allaqachon bor');
        }
        return Db::insert('users', [
            'customer_id' => null, 'email' => $email, 'name' => trim($name) ?: $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'role' => 'staff', 'created_at' => Util::now(),
        ]);
    }

    public static function staffCount(): int
    {
        return (int)Db::value("SELECT COUNT(*) FROM users WHERE role = 'staff'");
    }

    public static function setPassword(int $userId, string $password): void
    {
        Db::update('users', $userId, ['password_hash' => password_hash($password, PASSWORD_DEFAULT), 'must_change_password' => 0]);
    }
}
