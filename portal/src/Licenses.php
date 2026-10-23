<?php
declare(strict_types=1);

/**
 * Litsenziya serveri yadrosi: kalit berish, faollashtirish, tekshirish, qurilmani bo'shatish.
 * Protokol: docs/TOOL_CONTRACT.md (§3).
 */
final class Licenses
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // 0/O, 1/I yo'q
    public const TYPE_UZ = ['trial' => 'Sinov', 'paid' => 'Pullik', 'internal' => 'Ichki'];
    public const STATUS_UZ = ['active' => 'Faol', 'suspended' => "To'xtatilgan", 'revoked' => 'Bekor qilingan', 'expired' => 'Muddati tugagan', 'pending' => 'Hali boshlanmagan'];

    // ---------------------------------------------------------------- kalit

    public static function newKey(string $toolCode): string
    {
        for ($i = 0; $i < 20; $i++) {
            $g = [];
            for ($j = 0; $j < 3; $j++) {
                $s = '';
                for ($k = 0; $k < 4; $k++) {
                    $s .= self::ALPHABET[random_int(0, 31)];
                }
                $g[] = $s;
            }
            $key = 'BW-' . strtoupper($toolCode) . '-' . implode('-', $g);
            if (!Db::value('SELECT 1 FROM licenses WHERE license_key = ?', [$key])) {
                return $key;
            }
        }
        throw new RuntimeException('Kalit yaratib bo\'lmadi');
    }

    /** "bw rej abcd..." kabi kiritilgan kalitni standart ko'rinishga keltiradi. */
    public static function normalizeKey(string $k): string
    {
        $k = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $k) ?? '');
        if (preg_match('/^BW([A-Z0-9]{3})([A-Z0-9]{4})([A-Z0-9]{4})([A-Z0-9]{4})$/', $k, $m)) {
            return "BW-{$m[1]}-{$m[2]}-{$m[3]}-{$m[4]}";
        }
        return '';
    }

    // ---------------------------------------------------------------- o'qish

    public static function get(int $id): ?array
    {
        return Db::one(self::SELECT . ' WHERE l.id = ?', [$id]);
    }

    public static function byKey(string $key): ?array
    {
        return Db::one(self::SELECT . ' WHERE l.license_key = ?', [$key]);
    }

    private const SELECT = 'SELECT l.*, t.slug AS tool_slug, t.code AS tool_code, t.name AS tool_name, c.name AS customer_name, c.status AS customer_status
        FROM licenses l JOIN tools t ON t.id = l.tool_id JOIN customers c ON c.id = l.customer_id';

    /** @return list<array> */
    public static function forCustomer(int $customerId, ?int $toolId = null): array
    {
        $sql = self::SELECT . ' WHERE l.customer_id = ?';
        $args = [$customerId];
        if ($toolId !== null) {
            $sql .= ' AND l.tool_id = ?';
            $args[] = $toolId;
        }
        return array_map([self::class, 'decorate'], Db::all($sql . ' ORDER BY l.created_at DESC, l.id DESC', $args));
    }

    /** Qo'shimcha maydonlar: state, days_left, seats_used, limits/features massiv. */
    public static function decorate(array $l): array
    {
        $l['limits'] = json_decode((string)$l['limits'], true) ?: [];
        $l['features'] = json_decode((string)$l['features'], true) ?: [];
        $l['state'] = self::state($l);
        $l['days_left'] = Util::daysLeft($l['valid_until'] !== null ? (int)$l['valid_until'] : null);
        $l['seats_used'] = (int)Db::value("SELECT COUNT(*) FROM activations WHERE license_id = ? AND status = 'active'", [$l['id']]);
        $l['plan_name'] = (string)(Db::value('SELECT name FROM plans WHERE tool_id = ? AND code = ?', [$l['tool_id'], $l['plan_code']])
            ?? ['trial' => 'Sinov', 'custom' => 'Maxsus', 'internal' => 'Ichki'][$l['plan_code']] ?? ($l['plan_code'] ?: '—'));
        return $l;
    }

    /** 'active' | 'expired' | 'suspended' | 'revoked' | 'pending' (hisob bloklangan bo'lsa ham 'suspended'). */
    public static function state(array $l): string
    {
        if ($l['status'] === 'revoked') {
            return 'revoked';
        }
        if ($l['status'] === 'suspended' || ($l['customer_status'] ?? 'active') === 'blocked') {
            return 'suspended';
        }
        $now = Util::now();
        if ($l['valid_until'] !== null && (int)$l['valid_until'] < $now) {
            return 'expired';
        }
        return (int)$l['valid_from'] > $now ? 'pending' : 'active';
    }

    /** Vosita uchun eng mos litsenziya: faol pullik/ichki (eng uzoq muddatli) → faol sinov → eng oxirgisi. */
    public static function best(int $customerId, int $toolId): ?array
    {
        $all = self::forCustomer($customerId, $toolId);
        if (!$all) {
            return null;
        }
        usort($all, static function (array $a, array $b): int {
            $rank = static fn(array $l): int => ($l['state'] === 'active' ? 0 : 10) + ($l['type'] === 'trial' ? 1 : 0);
            return [$rank($a), -($a['valid_until'] ?? PHP_INT_MAX)] <=> [$rank($b), -($b['valid_until'] ?? PHP_INT_MAX)];
        });
        return $all[0];
    }

    // ---------------------------------------------------------------- jurnal

    public static function event(?int $licenseId, ?int $customerId, string $actor, string $action, string $detail = ''): void
    {
        Db::insert('license_events', [
            'ts' => Util::now(), 'license_id' => $licenseId, 'customer_id' => $customerId,
            'actor' => $actor, 'action' => $action, 'detail' => mb_substr($detail, 0, 500), 'ip' => Util::ip(),
        ]);
    }

    // ---------------------------------------------------------------- berish

    /**
     * Litsenziya berish. $in: customer_id, tool_id, type, plan_code?, seats?, valid_until (unix|null), limits?, features?,
     * check_days?, grace_days?, instance_url?, note?, valid_from?
     */
    public static function issue(array $in, string $actor, ?int $createdBy = null): array
    {
        $tool = Db::one('SELECT * FROM tools WHERE id = ?', [(int)$in['tool_id']]);
        $cust = Db::one('SELECT * FROM customers WHERE id = ?', [(int)$in['customer_id']]);
        if (!$tool || !$cust) {
            throw new HttpError(422, 'Korxona yoki vosita topilmadi');
        }
        $type = (string)($in['type'] ?? 'paid');
        if (!isset(self::TYPE_UZ[$type])) {
            throw new HttpError(422, "Litsenziya turi noto'g'ri");
        }
        $now = Util::now();
        $validUntil = array_key_exists('valid_until', $in) && $in['valid_until'] !== null ? (int)$in['valid_until'] : null;
        $validFrom = (int)($in['valid_from'] ?? $now);
        if ($validUntil !== null && $validUntil <= $validFrom) {
            throw new HttpError(422, "Tugash sanasi boshlanishdan keyin bo'lishi kerak");
        }
        $key = self::newKey($tool['code']);
        $id = Db::insert('licenses', [
            'license_key' => $key, 'customer_id' => (int)$cust['id'], 'tool_id' => (int)$tool['id'],
            'plan_code' => (string)($in['plan_code'] ?? ''), 'type' => $type, 'status' => 'active',
            'seats' => max(1, min(999, (int)($in['seats'] ?? 1))),
            'limits' => json_encode($in['limits'] ?? new stdClass(), JSON_UNESCAPED_UNICODE),
            'features' => json_encode(array_values($in['features'] ?? []), JSON_UNESCAPED_UNICODE),
            'valid_from' => $validFrom, 'valid_until' => $validUntil,
            'check_days' => max(1, min(90, (int)($in['check_days'] ?? 14))),
            'grace_days' => max(0, min(60, (int)($in['grace_days'] ?? 7))),
            'instance_url' => (string)($in['instance_url'] ?? ''),
            'note' => mb_substr((string)($in['note'] ?? ''), 0, 500),
            'created_by' => $createdBy, 'created_at' => $now, 'updated_at' => $now,
        ]);
        self::event($id, (int)$cust['id'], $actor, 'issued', self::TYPE_UZ[$type] . ' · ' . $tool['name'] . ' · ' . ($validUntil ? 'gacha ' . Util::date($validUntil) : 'muddatsiz'));
        return self::decorate(self::get($id));
    }

    /** Tarif bo'yicha litsenziya (muddat tarif kunlaridan). */
    public static function issueFromPlan(int $customerId, int $toolId, string $planCode, ?int $seats, string $actor, ?int $createdBy, string $note = '', ?string $untilYmd = null, string $instanceUrl = ''): array
    {
        $plan = Db::one('SELECT * FROM plans WHERE tool_id = ? AND code = ?', [$toolId, $planCode]);
        if (!$plan) {
            throw new HttpError(422, 'Tarif topilmadi');
        }
        $until = $untilYmd !== null && $untilYmd !== '' ? Util::endOfDay($untilYmd) : ($plan['days'] !== null ? Util::endOfDay(date('Y-m-d', Util::now() + (int)$plan['days'] * 86400)) : null);
        if ($untilYmd !== null && $untilYmd !== '' && $until === null) {
            throw new HttpError(422, "Sana noto'g'ri");
        }
        return self::issue([
            'customer_id' => $customerId, 'tool_id' => $toolId, 'plan_code' => $plan['code'], 'type' => $plan['type'],
            'seats' => $seats ?? (int)$plan['seats'], 'valid_until' => $until,
            'limits' => json_decode((string)$plan['limits'], true) ?: new stdClass(),
            'features' => json_decode((string)$plan['features'], true) ?: [],
            'note' => $note, 'instance_url' => $instanceUrl,
        ], $actor, $createdBy);
    }

    /** Sinov: har korxonaga har vositaga bir marta; faqat brauzerdagi demo uchun. */
    public static function startTrial(int $customerId, int $toolId, string $actor, ?int $createdBy): array
    {
        $tool = Db::one('SELECT * FROM tools WHERE id = ?', [$toolId]);
        if (!$tool || $tool['status'] !== 'active' || !(int)$tool['trial_enabled']) {
            throw new HttpError(422, "Bu vosita uchun sinov yo'q");
        }
        if (Db::value("SELECT 1 FROM licenses WHERE customer_id = ? AND tool_id = ? AND type = 'trial'", [$customerId, $toolId])) {
            throw new HttpError(409, 'Bu vosita uchun sinov allaqachon ishlatilgan');
        }
        $days = max(1, (int)$tool['trial_days']);
        return self::issue([
            'customer_id' => $customerId, 'tool_id' => $toolId, 'plan_code' => 'trial', 'type' => 'trial', 'seats' => 1,
            'valid_until' => Util::endOfDay(date('Y-m-d', Util::now() + $days * 86400)),
            'features' => ['web_demo'], 'note' => "Avtomatik sinov ($days kun)",
        ], $actor, $createdBy);
    }

    // ---------------------------------------------------------------- holat o'zgartirish (xodim)

    public static function setStatus(int $id, string $status, string $actor, string $reason = ''): void
    {
        $l = self::get($id) ?? throw new HttpError(404, 'Litsenziya topilmadi');
        if (!in_array($status, ['active', 'suspended', 'revoked'], true)) {
            throw new HttpError(422, 'Holat noto\'g\'ri');
        }
        Db::update('licenses', $id, ['status' => $status, 'updated_at' => Util::now()]);
        self::event($id, (int)$l['customer_id'], $actor, ['active' => 'resumed', 'suspended' => 'suspended', 'revoked' => 'revoked'][$status], $reason);
    }

    /** Muddatni belgilangan sanagacha (YYYY-MM-DD) yoki muddatsiz ('') qilish. */
    public static function setUntil(int $id, string $untilYmd, string $actor): void
    {
        $l = self::get($id) ?? throw new HttpError(404, 'Litsenziya topilmadi');
        $until = null;
        if ($untilYmd !== '') {
            $until = Util::endOfDay($untilYmd) ?? throw new HttpError(422, "Sana noto'g'ri");
            if ($until <= (int)$l['valid_from']) {
                throw new HttpError(422, "Tugash sanasi boshlanishdan keyin bo'lishi kerak");
            }
        }
        Db::update('licenses', $id, ['valid_until' => $until, 'updated_at' => Util::now()]);
        self::event($id, (int)$l['customer_id'], $actor, 'extended', ($l['valid_until'] ? Util::date((int)$l['valid_until']) : 'muddatsiz') . ' → ' . ($until ? Util::date($until) : 'muddatsiz'));
    }

    public static function setSeats(int $id, int $seats, string $actor): void
    {
        $l = self::get($id) ?? throw new HttpError(404, 'Litsenziya topilmadi');
        $seats = max(1, min(999, $seats));
        Db::update('licenses', $id, ['seats' => $seats, 'updated_at' => Util::now()]);
        self::event($id, (int)$l['customer_id'], $actor, 'seats', $l['seats'] . ' → ' . $seats);
    }

    // ---------------------------------------------------------------- qurilmalar

    /** @return list<array> */
    public static function activations(int $licenseId, bool $onlyActive = true): array
    {
        return Db::all('SELECT * FROM activations WHERE license_id = ?' . ($onlyActive ? " AND status = 'active'" : '') . ' ORDER BY last_seen DESC', [$licenseId]);
    }

    public static function releaseActivation(int $activationId, string $actor): void
    {
        $a = Db::one('SELECT a.*, l.customer_id FROM activations a JOIN licenses l ON l.id = a.license_id WHERE a.id = ?', [$activationId])
            ?? throw new HttpError(404, 'Qurilma topilmadi');
        if ($a['status'] === 'released') {
            return;
        }
        Db::update('activations', $activationId, ['status' => 'released', 'released_at' => Util::now(), 'released_by' => $actor]);
        self::event((int)$a['license_id'], (int)$a['customer_id'], $actor, 'device_released', $a['device_name'] ?: substr($a['device_id'], 0, 12));
    }

    // ---------------------------------------------------------------- API: faollashtirish va tekshirish

    /** @return array{token:string, license:array} */
    public static function activate(string $key, string $deviceId, array $meta, ?string $toolCode): array
    {
        $lic = self::requireUsable($key, $toolCode);
        if ($lic['type'] === 'trial') {
            throw new LicenseError('trial_no_app', "Sinov litsenziyasi faqat brauzerdagi demo uchun. Ilovani faollashtirish uchun pullik litsenziya kerak", 403);
        }
        $deviceId = self::cleanDevice($deviceId);
        $now = Util::now();
        $res = Db::tx(function () use ($lic, $deviceId, $meta, $now) {
            $act = Db::one('SELECT * FROM activations WHERE license_id = ? AND device_id = ?', [$lic['id'], $deviceId]);
            $fields = [
                'device_name' => mb_substr((string)($meta['device_name'] ?? ''), 0, 80),
                'platform' => mb_substr((string)($meta['platform'] ?? ''), 0, 40),
                'app_version' => mb_substr((string)($meta['app_version'] ?? ''), 0, 20),
                'last_seen' => $now, 'last_ip' => Util::ip(),
            ];
            if ($act && $act['status'] === 'active') {
                Db::update('activations', (int)$act['id'], $fields);
                return [$act['id'], false];
            }
            $used = (int)Db::value("SELECT COUNT(*) FROM activations WHERE license_id = ? AND status = 'active'", [$lic['id']]);
            if ($used >= (int)$lic['seats']) {
                $names = array_map(static fn($a) => $a['device_name'] ?: substr($a['device_id'], 0, 8), self::activations((int)$lic['id']));
                throw new LicenseError('seats_full', "Barcha o'rindiqlar band ({$used}/{$lic['seats']}). Portaldan eski qurilmani bo'shating yoki o'rindiq qo'shishni so'rang", 409, ['seats' => (int)$lic['seats'], 'devices' => $names]);
            }
            if ($act) {
                Db::update('activations', (int)$act['id'], $fields + ['status' => 'active', 'released_at' => null, 'released_by' => '']);
                return [$act['id'], true];
            }
            $id = Db::insert('activations', ['license_id' => $lic['id'], 'device_id' => $deviceId, 'status' => 'active', 'first_seen' => $now] + $fields);
            return [$id, true];
        });
        if ($res[1]) {
            self::event((int)$lic['id'], (int)$lic['customer_id'], 'api', 'activated', ($meta['device_name'] ?? '') . ' · ' . ($meta['platform'] ?? '') . ' · v' . ($meta['app_version'] ?? '?'));
        }
        return ['token' => self::buildToken($lic, $deviceId), 'license' => self::publicView($lic)];
    }

    /** Davriy tekshiruv: qurilma faol bo'lsa yangi token beradi. */
    public static function check(string $key, string $deviceId, array $meta, ?string $toolCode): array
    {
        $deviceId = self::cleanDevice($deviceId);
        $lic = self::requireUsable($key, $toolCode);
        $act = Db::one("SELECT * FROM activations WHERE license_id = ? AND device_id = ? AND status = 'active'", [$lic['id'], $deviceId]);
        if (!$act) {
            throw new LicenseError('not_activated', "Bu qurilma faollashtirilmagan yoki o'rindiq bo'shatilgan. Kalitni qayta kiriting", 403);
        }
        Db::update('activations', (int)$act['id'], [
            'last_seen' => Util::now(), 'last_ip' => Util::ip(),
            'app_version' => mb_substr((string)($meta['app_version'] ?? $act['app_version']), 0, 20),
        ]);
        return ['token' => self::buildToken($lic, $deviceId), 'license' => self::publicView($lic)];
    }

    public static function deactivate(string $key, string $deviceId): void
    {
        $key = self::normalizeKey($key);
        $lic = $key !== '' ? self::byKey($key) : null;
        if (!$lic) {
            throw new LicenseError('invalid_key', 'Litsenziya kaliti topilmadi', 404);
        }
        $act = Db::one("SELECT id FROM activations WHERE license_id = ? AND device_id = ? AND status = 'active'", [$lic['id'], self::cleanDevice($deviceId)]);
        if ($act) {
            self::releaseActivation((int)$act['id'], 'api');
        }
    }

    private static function cleanDevice(string $d): string
    {
        $d = strtolower(trim($d));
        if (!preg_match('/^[a-f0-9]{16,64}$/', $d)) {
            throw new LicenseError('bad_request', "Qurilma identifikatori noto'g'ri (16–64 ta hex belgi)", 400);
        }
        return $d;
    }

    /** Kalit bo'yicha litsenziyani topadi va ishlatish mumkinligini tekshiradi. */
    private static function requireUsable(string $key, ?string $toolCode): array
    {
        $norm = self::normalizeKey($key);
        $row = $norm !== '' ? self::byKey($norm) : null;
        if (!$row) {
            throw new LicenseError('invalid_key', 'Litsenziya kaliti topilmadi. Kalitni tekshirib qayta kiriting', 404);
        }
        if ($toolCode !== null && $toolCode !== '' && strcasecmp($toolCode, $row['tool_code']) !== 0 && strcasecmp($toolCode, $row['tool_slug']) !== 0) {
            throw new LicenseError('tool_mismatch', "Bu kalit boshqa vosita ({$row['tool_name']}) uchun", 403);
        }
        $lic = self::decorate($row);
        switch ($lic['state']) {
            case 'revoked':
                throw new LicenseError('revoked', "Litsenziya bekor qilingan. Biwork bilan bog'laning", 403);
            case 'suspended':
                throw new LicenseError('suspended', "Litsenziya vaqtincha to'xtatilgan. Biwork bilan bog'laning", 403);
            case 'expired':
                throw new LicenseError('expired', 'Litsenziya muddati tugagan (' . Util::date((int)$lic['valid_until']) . "). Uzaytirish uchun Biwork bilan bog'laning", 403);
            case 'pending':
                throw new LicenseError('not_started', 'Litsenziya hali boshlanmagan (boshlanishi: ' . Util::date((int)$lic['valid_from']) . ')', 403);
        }
        return $lic;
    }

    private static function publicView(array $l): array
    {
        return [
            'key' => $l['license_key'], 'tool' => $l['tool_slug'], 'customer' => $l['customer_name'], 'type' => $l['type'],
            'plan' => $l['plan_code'], 'seats' => (int)$l['seats'], 'seats_used' => (int)$l['seats_used'],
            'valid_until' => $l['valid_until'] !== null ? (int)$l['valid_until'] : null, 'days_left' => $l['days_left'],
        ];
    }

    /** Imzolangan litsenziya tokeni (bwk1). */
    public static function buildToken(array $l, string $deviceId): string
    {
        $now = Util::now();
        $exp = $l['valid_until'] !== null ? (int)$l['valid_until'] : null;
        $chk = $now + (int)$l['check_days'] * 86400;
        if ($exp !== null) {
            $chk = min($chk, $exp);
        }
        return Tokens::sign(Tokens::LICENSE, [
            'v' => 1, 'iss' => 'biwork', 'jti' => bin2hex(random_bytes(8)),
            'lic' => $l['license_key'], 'lid' => (int)$l['id'],
            'tool' => $l['tool_slug'], 'tool_code' => $l['tool_code'],
            'cust' => ['id' => (int)$l['customer_id'], 'name' => $l['customer_name']],
            'type' => $l['type'], 'plan' => $l['plan_code'],
            'features' => $l['features'], 'limits' => (object)$l['limits'], 'seats' => (int)$l['seats'],
            'dev' => $deviceId, 'iat' => $now, 'nbf' => (int)$l['valid_from'], 'exp' => $exp,
            'chk' => $chk, 'grace' => (int)$l['grace_days'] * 86400,
        ]);
    }
}
