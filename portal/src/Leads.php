<?php
declare(strict_types=1);

/** Ochiq saytdan kelgan murojaatlar ("so'rov qoldirish" va "kutish ro'yxati"). Hisobsiz, telefon yoki email yetarli. */
final class Leads
{
    public const STATUS_UZ = ['new' => 'Yangi', 'contacted' => "Bog'lanildi", 'won' => 'Mijoz bo\'ldi', 'lost' => 'Rad etdi'];

    /** "+998 (90) 123-45-67" / "901234567" → "+998901234567". Noto'g'ri bo'lsa ''. */
    public static function phone(string $s): string
    {
        $d = preg_replace('/\D+/', '', $s) ?? '';
        if (strlen($d) === 9) {
            $d = '998' . $d;
        }
        return strlen($d) >= 10 && strlen($d) <= 15 ? '+' . $d : '';
    }

    /**
     * @return array{ok:bool, id:?int} ok=false — tekshiruvdan o'tmadi (xabar HttpError 422 da); id=null — bot deb hisoblab tashlandi.
     * @throws HttpError
     */
    public static function create(array $in): array
    {
        $s = static fn(string $k, int $max): string => is_string($in[$k] ?? null) ? trim(mb_substr(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $in[$k]) ?? '', 0, $max)) : '';
        // bot tuzoqlari: yashirin maydon to'ldirilgan yoki forma 2 soniyadan tez yuborilgan — "muvaffaqiyatli" ko'rsatib, saqlamaymiz
        $trap = $s('website', 100) !== '' || (isset($in['t']) && ctype_digit((string)$in['t']) && Util::now() - (int)$in['t'] < 2);
        $name = $s('name', 100);
        $company = $s('company', 150);
        $phoneRaw = $s('phone', 40);
        $phone = self::phone($phoneRaw);
        $email = mb_strtolower($s('email', 120));
        $msg = $s('message', 1000);
        if (mb_strlen($name) < 2) {
            throw new HttpError(422, 'Ismingizni yozing');
        }
        if ($phoneRaw !== '' && $phone === '') {
            throw new HttpError(422, "Telefon raqami noto'g'ri. Masalan: +998 90 123 45 67");
        }
        if ($email !== '' && !Util::emailOk($email)) {
            throw new HttpError(422, "Email manzili noto'g'ri");
        }
        if ($phone === '' && $email === '') {
            throw new HttpError(422, "Bog'lanish uchun telefon raqami yoki email yozing");
        }
        if ($trap) {
            return ['ok' => true, 'id' => null];
        }
        if (!RateLimit::hit('lead:' . Util::ip(), 5, 3600) || !RateLimit::hit('lead:all', 300, 86400)) {
            throw new HttpError(429, "Juda ko'p so'rov yuborildi. Keyinroq qayta urinib ko'ring");
        }
        $tool = $s('tool', 40) !== '' ? Catalog::tool($s('tool', 40)) : null;
        $now = Util::now();
        $id = Db::insert('leads', [
            'tool_id' => $tool ? (int)$tool['id'] : null, 'name' => $name, 'company' => $company, 'phone' => $phone, 'email' => $email,
            'message' => $msg, 'source' => Marketing::source(), 'status' => 'new', 'note' => '', 'ip' => Util::ip(), 'created_at' => $now, 'updated_at' => $now,
        ]);
        Notify::lead(Db::one('SELECT * FROM leads WHERE id = ?', [$id]) ?? [], $tool);
        return ['ok' => true, 'id' => $id];
    }

    public static function newCount(): int
    {
        return (int)Db::value("SELECT COUNT(*) FROM leads WHERE status = 'new'");
    }

    /** @return list<array> */
    public static function all(string $status = '', int $toolId = 0, int $limit = 200, int $offset = 0): array
    {
        $w = [];
        $a = [];
        if (isset(self::STATUS_UZ[$status])) {
            $w[] = 'l.status = ?';
            $a[] = $status;
        }
        if ($toolId > 0) {
            $w[] = 'l.tool_id = ?';
            $a[] = $toolId;
        }
        return Db::all('SELECT l.*, t.name AS tool_name, t.slug AS tool_slug FROM leads l LEFT JOIN tools t ON t.id = l.tool_id'
            . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY l.created_at DESC, l.id DESC LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset, $a);
    }

    public static function update(int $id, string $status, string $note): void
    {
        if (!isset(self::STATUS_UZ[$status])) {
            throw new HttpError(422, "Holat noto'g'ri");
        }
        Db::update('leads', $id, ['status' => $status, 'note' => mb_substr($note, 0, 1000), 'updated_at' => Util::now()]);
    }
}
