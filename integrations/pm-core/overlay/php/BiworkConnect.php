<?php
declare(strict_types=1);

/**
 * Biwork Connect — vositani Biwork platformasiga ulash kutubxonasi (PHP 8.1+, libsodium).
 * Bitta fayl, tashqi bog'liqliksiz. Shartnoma: docs/TOOL_CONTRACT.md.
 *
 *   $bw = new BiworkConnect([
 *       'server'      => 'https://biwork.uz',          // portal manzili
 *       'tool'        => 'reja',                        // vosita slug yoki kodi
 *       'data_dir'    => __DIR__ . '/data',             // yoziladigan papka (litsenziya, qurilma izi)
 *       'public_keys' => __DIR__ . '/biwork-public.json', // /api/v1/pubkey javobi (paket bilan keladi)
 *       'app_version' => '1.8',
 *   ]);
 *   $st = $bw->ensure();            // har ishga tushishda/so'rovda: ['allowed'=>bool, 'state'=>..., 'message'=>...]
 *   $bw->activate('BW-REJ-....');   // kalit kiritilganda
 *   $claims = $bw->verifyLaunch($_GET['bw_launch']);   // portaldan "Ochish" orqali kelganda
 */
final class BiworkException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}

final class BiworkConnect
{
    public const VERSION = '1.0';
    private const LICENSE = 'bwk1';
    private const LAUNCH = 'bwl1';
    /** Oflayn holatda tekshiruv oynasi o'tgach, yangilashga urinishlar orasidagi minimal vaqt. */
    private const RETRY_SEC = 900;

    private string $dir;
    /** @var array<string,string> kid => xom ochiq kalit */
    private array $keys = [];

    /**
     * @param array{server:string, tool:string, data_dir:string, public_keys:array|string, app_version?:string, device_name?:string,
     *              http?:callable, now?:callable, timeout?:int} $cfg
     */
    public function __construct(private array $cfg)
    {
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            throw new BiworkException('no_sodium', "PHP 'sodium' kengaytmasi kerak");
        }
        foreach (['server', 'tool', 'data_dir', 'public_keys'] as $k) {
            if (empty($cfg[$k])) {
                throw new BiworkException('config', "BiworkConnect: '$k' sozlanmagan");
            }
        }
        $this->dir = rtrim((string)$cfg['data_dir'], '/\\');
        $this->keys = self::loadKeys($cfg['public_keys']);
        if (!$this->keys) {
            throw new BiworkException('config', 'Biwork ochiq kalitlari topilmadi (public_keys)');
        }
    }

    // ------------------------------------------------------------------ ochiq kalitlar va tokenlarni tekshirish

    /** @return array<string,string> */
    public static function loadKeys(array|string $src): array
    {
        if (is_string($src)) {
            $raw = is_file($src) ? file_get_contents($src) : false;
            $src = $raw === false ? [] : (json_decode($raw, true) ?: []);
        }
        $list = isset($src['keys']) && is_array($src['keys']) ? $src['keys'] : $src;
        $out = [];
        foreach ($list as $k => $v) {
            if (is_array($v) && isset($v['kid'], $v['public'])) {
                $kid = (string)$v['kid'];
                $pub = (string)$v['public'];
            } else {
                $kid = (string)$k;
                $pub = (string)$v;
            }
            $bin = self::b64uDecode($pub);
            if ($bin !== false && strlen($bin) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                $out[$kid] = $bin;
            }
        }
        return $out;
    }

    /** Imzoni tekshiradi, payloadni qaytaradi. Muddatlarni tekshirmaydi. */
    public static function verifyToken(string $type, string $token, array $keys): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3 || $parts[0] !== $type) {
            throw new BiworkException('bad_token', 'Token formati noto\'g\'ri');
        }
        $json = self::b64uDecode($parts[1]);
        $sig = self::b64uDecode($parts[2]);
        $payload = $json === false ? null : json_decode($json, true);
        if (!is_array($payload) || $sig === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES) {
            throw new BiworkException('bad_token', 'Token buzilgan');
        }
        $pub = $keys[(string)($payload['kid'] ?? '')] ?? null;
        if ($pub === null || !sodium_crypto_sign_verify_detached($sig, $type . '.' . $parts[1], $pub)) {
            throw new BiworkException('bad_signature', 'Token imzosi noto\'g\'ri');
        }
        return $payload;
    }

    private static function b64uDecode(string $s): string|false
    {
        $s = strtr($s, '-_', '+/');
        $pad = strlen($s) % 4;
        return base64_decode($pad ? $s . str_repeat('=', 4 - $pad) : $s, true);
    }

    private function now(): int
    {
        return isset($this->cfg['now']) ? (int)($this->cfg['now'])() : time();
    }

    // ------------------------------------------------------------------ qurilma izi va saqlash

    /** Shu o'rnatishning barqaror identifikatori (32 hex). Papka boshqa kompyuterga ko'chirilsa o'zgaradi. */
    public function deviceId(): string
    {
        $f = $this->dir . '/biwork-device.id';
        $inst = is_file($f) ? trim((string)file_get_contents($f)) : '';
        if (!preg_match('/^[a-f0-9]{32}$/', $inst)) {
            $inst = bin2hex(random_bytes(16));
            $this->ensureDir();
            file_put_contents($f, $inst . "\n", LOCK_EX);
        }
        $machine = '';
        foreach (['/etc/machine-id', '/var/lib/dbus/machine-id'] as $m) {
            if (is_readable($m)) {
                $machine = trim((string)file_get_contents($m));
                break;
            }
        }
        return substr(hash('sha256', $inst . '|' . php_uname('n') . '|' . $machine), 0, 32);
    }

    public function deviceName(): string
    {
        return (string)($this->cfg['device_name'] ?? php_uname('n'));
    }

    private function ensureDir(): void
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
            throw new BiworkException('storage', 'Litsenziya papkasini yaratib bo\'lmadi: ' . $this->dir);
        }
    }

    private function load(): array
    {
        $f = $this->dir . '/biwork-license.json';
        $d = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
        return is_array($d) ? $d : [];
    }

    private function save(array $d): void
    {
        $this->ensureDir();
        $f = $this->dir . '/biwork-license.json';
        $tmp = $f . '.' . bin2hex(random_bytes(3)) . '.tmp';
        if (file_put_contents($tmp, json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) === false || !@rename($tmp, $f)) {
            @unlink($tmp);
            throw new BiworkException('storage', 'Litsenziyani saqlab bo\'lmadi: ' . $f);
        }
        @chmod($f, 0640);
    }

    // ------------------------------------------------------------------ holat (oflayn)

    /**
     * Saqlangan tokenni internetsiz baholaydi.
     * state: none | valid | grace | expired | locked | blocked | invalid | clock
     * @return array{allowed:bool, state:string, message:string, license:?array, days_left:?int, needs_refresh:bool}
     */
    public function state(): array
    {
        $d = $this->load();
        $res = static fn(bool $ok, string $st, string $msg, ?array $lic = null, ?int $days = null, bool $refresh = false): array
            => ['allowed' => $ok, 'state' => $st, 'message' => $msg, 'license' => $lic, 'days_left' => $days, 'needs_refresh' => $refresh];
        if (empty($d['token'])) {
            return $res(false, 'none', 'Litsenziya faollashtirilmagan. Litsenziya kalitini kiriting.');
        }
        try {
            $c = self::verifyToken(self::LICENSE, (string)$d['token'], $this->keys);
        } catch (BiworkException $e) {
            return $res(false, 'invalid', 'Litsenziya fayli yaroqsiz: ' . $e->getMessage() . '. Kalitni qayta kiriting.');
        }
        $tool = (string)$this->cfg['tool'];
        if (strcasecmp((string)($c['tool'] ?? ''), $tool) !== 0 && strcasecmp((string)($c['tool_code'] ?? ''), $tool) !== 0) {
            return $res(false, 'invalid', 'Litsenziya boshqa vosita uchun.', $c);
        }
        if (!hash_equals((string)($c['dev'] ?? ''), $this->deviceId())) {
            return $res(false, 'invalid', "Litsenziya boshqa qurilmaga bog'langan. Shu qurilmada kalitni qayta kiriting.", $c);
        }
        if (!empty($d['blocked'])) {
            return $res(false, 'blocked', (string)($d['blocked']['message'] ?? 'Litsenziya bloklangan.'), $c);
        }
        $now = $this->now();
        $seen = max((int)($d['max_seen'] ?? 0), (int)($d['server_time'] ?? 0));
        if ($seen > 0 && $now + 86400 < $seen) {
            return $res(false, 'clock', "Kompyuter soati orqaga surilgan. Soatni to'g'rilab, internetga ulanib qayta urining.", $c, null, true);
        }
        if ($now > $seen) {
            $d['max_seen'] = $now;
            try {
                $this->save($d);
            } catch (BiworkException) {
                // faqat o'qish rejimida ham ishlayversin
            }
        }
        $exp = isset($c['exp']) ? (int)$c['exp'] : null;
        $days = $exp === null ? null : (int)ceil(($exp - $now) / 86400);
        if ((int)($c['nbf'] ?? 0) > $now + 86400) {
            return $res(false, 'invalid', 'Litsenziya hali boshlanmagan.', $c, $days);
        }
        if ($exp !== null && $now > $exp) {
            return $res(false, 'expired', 'Litsenziya muddati tugagan. Uzaytirish uchun Biwork bilan bog\'laning.', $c, $days, true);
        }
        $chk = (int)($c['chk'] ?? 0);
        $grace = (int)($c['grace'] ?? 0);
        if ($now > $chk + $grace) {
            return $res(false, 'locked', "Litsenziyani onlayn tekshirish muddati o'tdi. Internetga ulanib, qayta urining.", $c, $days, true);
        }
        if ($now > $chk) {
            return $res(true, 'grace', "Litsenziyani onlayn tekshirish kerak (qolgan imtiyoz: " . (int)ceil(($chk + $grace - $now) / 86400) . ' kun).', $c, $days, true);
        }
        return $res(true, 'valid', 'Litsenziya faol.', $c, $days, $now > $chk - 86400);
    }

    /**
     * Vosita har ishga tushganda/so'rovda chaqiradi: kerak bo'lsa (tekshiruv muddati yaqin/o'tgan) onlayn yangilaydi,
     * internet bo'lmasa imtiyoz muddati ichida ishlashda davom etadi.
     */
    public function ensure(): array
    {
        $st = $this->state();
        $d = $this->load();
        if ($st['state'] === 'blocked') {
            // to'xtatilgan/muddati tugagan litsenziya tiklanishi mumkin (to'lov, uzaytirish): vaqti-vaqti bilan qayta so'raymiz;
            // bekor qilingan/bo'shatilgan/begona kalit esa faqat qayta faollashtirish bilan ochiladi
            if (!in_array((string)($d['blocked']['code'] ?? ''), ['suspended', 'expired', 'not_started'], true)) {
                return $st;
            }
        } elseif (!$st['needs_refresh'] || $st['state'] === 'none' || $st['state'] === 'invalid') {
            return $st;
        }
        if ($this->now() - (int)($d['last_attempt'] ?? 0) < self::RETRY_SEC) {
            return $st;
        }
        try {
            return $this->refresh();
        } catch (BiworkException) {
            return $this->state();
        }
    }

    // ------------------------------------------------------------------ server bilan

    /** Kalit bilan faollashtirish. Muvaffaqiyatda yangi holatni qaytaradi, aks holda BiworkException. */
    public function activate(string $key): array
    {
        $r = $this->call('activate', ['key' => $key, 'tool' => $this->cfg['tool'],
            'device_name' => $this->deviceName(), 'platform' => PHP_OS_FAMILY, 'app_version' => (string)($this->cfg['app_version'] ?? '')]);
        $this->store($key, $r);
        return $this->state();
    }

    /** Davriy tekshiruv: yangi token oladi. Server rad etsa (bekor/to'xtatilgan/muddat) — darhol bloklaydi. */
    public function refresh(): array
    {
        $d = $this->load();
        if (empty($d['key'])) {
            throw new BiworkException('not_activated', 'Litsenziya faollashtirilmagan');
        }
        $d['last_attempt'] = $this->now();
        $this->save($d);
        try {
            $r = $this->call('check', ['key' => $d['key'], 'tool' => $this->cfg['tool'], 'app_version' => (string)($this->cfg['app_version'] ?? '')]);
        } catch (BiworkException $e) {
            if (in_array($e->errorCode, ['revoked', 'suspended', 'expired', 'invalid_key', 'not_activated', 'tool_mismatch', 'not_started'], true)) {
                $d = $this->load();
                $d['blocked'] = ['code' => $e->errorCode, 'message' => $e->getMessage(), 'at' => $this->now()];
                $this->save($d);
            }
            throw $e;
        }
        $this->store((string)$d['key'], $r);
        return $this->state();
    }

    /** Shu qurilmani bo'shatadi (o'rindiq qaytadi) va mahalliy litsenziyani o'chiradi. */
    public function deactivate(): void
    {
        $d = $this->load();
        if (!empty($d['key'])) {
            try {
                $this->call('deactivate', ['key' => $d['key']]);
            } finally {
                @unlink($this->dir . '/biwork-license.json');
            }
        }
    }

    private function store(string $key, array $r): void
    {
        $token = (string)($r['token'] ?? '');
        $c = self::verifyToken(self::LICENSE, $token, $this->keys); // imzosiz javobni saqlamaymiz
        if (!hash_equals((string)($c['dev'] ?? ''), $this->deviceId())) {
            throw new BiworkException('bad_token', 'Server boshqa qurilma uchun token qaytardi');
        }
        $d = $this->load();
        $d = ['key' => strtoupper(trim($key)), 'token' => $token, 'saved_at' => $this->now(), 'server_time' => (int)($r['server_time'] ?? 0),
            'max_seen' => max((int)($d['max_seen'] ?? 0), $this->now(), (int)($r['server_time'] ?? 0)), 'last_attempt' => $this->now(), 'blocked' => null];
        $this->save($d);
    }

    /** @return array javob (ok=true); xatoda BiworkException(code, message) */
    private function call(string $op, array $body): array
    {
        $body['device_id'] = $this->deviceId();
        $url = rtrim((string)$this->cfg['server'], '/') . '/api/v1/licenses/' . $op;
        [$status, $json] = isset($this->cfg['http']) ? ($this->cfg['http'])('POST', $url, $body) : self::httpPost($url, $body, (int)($this->cfg['timeout'] ?? 8));
        if (($json['ok'] ?? false) === true) {
            return $json;
        }
        if (isset($json['error']['code'])) {
            throw new BiworkException((string)$json['error']['code'], (string)($json['error']['message'] ?? 'Litsenziya xatosi'));
        }
        throw new BiworkException('network', "Litsenziya serveriga ulanib bo'lmadi (HTTP $status)");
    }

    /** @return array{0:int,1:array} */
    private static function httpPost(string $url, array $body, int $timeout): array
    {
        $payload = json_encode($body, JSON_UNESCAPED_UNICODE);
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
                CURLOPT_CONNECTTIMEOUT => $timeout, CURLOPT_TIMEOUT => $timeout, CURLOPT_FOLLOWLOCATION => false]);
            $raw = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
        } else {
            $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
                'content' => $payload, 'timeout' => $timeout, 'ignore_errors' => true]]);
            $raw = @file_get_contents($url, false, $ctx);
            $status = 0;
            foreach ($http_response_header ?? [] as $h) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                    $status = (int)$m[1];
                }
            }
        }
        $json = is_string($raw) ? json_decode($raw, true) : null;
        return [$status, is_array($json) ? $json : []];
    }

    // ------------------------------------------------------------------ "Ochish" tokeni (portal → vosita)

    /**
     * Portal bergan bir martalik "Ochish" tokenini tekshiradi (imzo, auditoriya, muddat, takrorlanmaslik).
     * @return array payload: user{id,name,email,role,tool_role}, cust{id,name}, lic{...}, mode ('demo'|'live'), portal
     */
    public function verifyLaunch(string $token): array
    {
        $p = self::verifyToken(self::LAUNCH, $token, $this->keys);
        if (strcasecmp((string)($p['aud'] ?? ''), (string)$this->cfg['tool']) !== 0) {
            throw new BiworkException('wrong_audience', 'Token boshqa vosita uchun berilgan');
        }
        $now = $this->now();
        if ((int)($p['exp'] ?? 0) < $now || (int)($p['iat'] ?? PHP_INT_MAX) > $now + 120) {
            throw new BiworkException('expired', "Kirish havolasi eskirgan. Portaldan qayta oching.");
        }
        $this->ensureDir();
        $f = $this->dir . '/biwork-jti.json';
        $fh = fopen($f, 'c+');
        if ($fh === false) {
            throw new BiworkException('storage', 'Papkaga yozib bo\'lmadi');
        }
        try {
            flock($fh, LOCK_EX);
            $seen = json_decode((string)stream_get_contents($fh), true);
            $seen = is_array($seen) ? array_filter($seen, static fn($t) => (int)$t > $now) : [];
            $jti = (string)($p['jti'] ?? '');
            if ($jti === '' || isset($seen[$jti])) {
                throw new BiworkException('replay', 'Kirish havolasi allaqachon ishlatilgan. Portaldan qayta oching.');
            }
            $seen[$jti] = (int)$p['exp'] + 60;
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, json_encode($seen));
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
        return $p;
    }
}
