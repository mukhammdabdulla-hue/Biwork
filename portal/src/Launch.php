<?php
declare(strict_types=1);

/**
 * "Ochish": portal → vosita. Qisqa muddatli imzolangan token (bwl1, 2 daqiqa) bilan vosita manziliga yo'naltiradi.
 * Vosita tokenni ochiq kalit bilan tekshiradi va foydalanuvchi uchun o'z sessiyasini ochadi (docs/TOOL_CONTRACT.md §4).
 */
final class Launch
{
    public const TTL = 120;

    /** @return string vositaga yo'naltirish manzili */
    public static function url(array $user, array $lic, array $tool): string
    {
        $base = $lic['instance_url'] !== '' ? $lic['instance_url'] : $tool['web_url'];
        if ($base === '') {
            throw new HttpError(422, "Bu vositaning brauzer versiyasi hali ulanmagan");
        }
        if (!preg_match('#^https?://#i', $base) && !str_starts_with($base, '/')) {
            throw new HttpError(500, "Vosita manzili noto'g'ri sozlangan");
        }
        if ($lic['state'] !== 'active') {
            throw new HttpError(403, 'Litsenziya faol emas: ' . (Licenses::STATUS_UZ[$lic['state']] ?? $lic['state']));
        }
        $now = Util::now();
        $payload = [
            'v' => 1, 'iss' => 'biwork', 'aud' => $tool['slug'], 'jti' => bin2hex(random_bytes(12)),
            'iat' => $now, 'exp' => $now + self::TTL,
            'mode' => $lic['instance_url'] !== '' && $lic['type'] !== 'trial' ? 'live' : 'demo',
            'user' => [
                'id' => (int)$user['id'], 'name' => $user['name'] ?: $user['email'], 'email' => $user['email'],
                'role' => $user['role'], 'tool_role' => $user['role'] === 'member' ? 'user' : 'admin',
            ],
            'cust' => ['id' => (int)$lic['customer_id'], 'name' => $lic['customer_name']],
            'lic' => [
                'id' => (int)$lic['id'], 'type' => $lic['type'], 'plan' => $lic['plan_code'],
                'features' => $lic['features'], 'limits' => (object)$lic['limits'],
                'exp' => $lic['valid_until'] !== null ? (int)$lic['valid_until'] : null,
            ],
            'portal' => Util::absUrl('/app/tools/' . $tool['slug']),
        ];
        $token = Tokens::sign(Tokens::LAUNCH, $payload);
        Licenses::event((int)$lic['id'], (int)$lic['customer_id'], 'user:' . $user['id'], 'launch', $tool['name'] . ' (' . $payload['mode'] . ')');
        $hash = '';
        if (($p = strpos($base, '#')) !== false) {
            $hash = substr($base, $p);
            $base = substr($base, 0, $p);
        }
        return $base . (str_contains($base, '?') ? '&' : '?') . 'bw_launch=' . rawurlencode($token) . $hash;
    }
}
