<?php
declare(strict_types=1);

/**
 * Imzolangan tokenlar (Ed25519, libsodium). Format: <tur>.<base64url(payload JSON)>.<base64url(imzo)>
 * Imzo "<tur>.<base64url(payload)>" satri ustida — shuning uchun bir tur token boshqa tur o'rnida o'tmaydi.
 *   bwk1 — litsenziya tokeni (vosita saqlaydi va oflayn tekshiradi)
 *   bwl1 — "Ochish" tokeni (portal → vosita, 2 daqiqa)
 * Ochiq kalit(lar) /api/v1/pubkey da; vositalar uni o'z paketida saqlaydi (docs/TOOL_CONTRACT.md).
 */
final class Tokens
{
    public const LICENSE = 'bwk1';
    public const LAUNCH = 'bwl1';

    private static ?array $cur = null;

    private static function dir(): string
    {
        return Config::dataDir() . '/keys';
    }

    /** Joriy imzolovchi kalit: ['kid','secret'(bin),'public'(bin)]. Yo'q bo'lsa — yaratiladi. */
    public static function current(): array
    {
        if (self::$cur !== null) {
            return self::$cur;
        }
        $dir = self::dir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $curFile = $dir . '/current.txt';
        $kid = is_file($curFile) ? trim((string)file_get_contents($curFile)) : '';
        if ($kid === '' || !is_file("$dir/$kid.sec") || !is_file("$dir/$kid.pub")) {
            self::generate();
            $kid = trim((string)file_get_contents($curFile));
        }
        $sec = base64_decode(trim((string)file_get_contents("$dir/$kid.sec")), true);
        $pub = base64_decode(trim((string)file_get_contents("$dir/$kid.pub")), true);
        if ($sec === false || $pub === false || strlen($sec) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new RuntimeException('Imzolash kaliti buzilgan: ' . $kid);
        }
        return self::$cur = ['kid' => $kid, 'secret' => $sec, 'public' => $pub];
    }

    /** Yangi kalit juftligi yaratib, joriy qilib belgilaydi (eskilari ochiq kalit ro'yxatida qoladi). */
    public static function generate(): string
    {
        $dir = self::dir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $kp = sodium_crypto_sign_keypair();
        $pub = sodium_crypto_sign_publickey($kp);
        $sec = sodium_crypto_sign_secretkey($kp);
        $kid = substr(hash('sha256', $pub), 0, 8);
        file_put_contents("$dir/$kid.sec", base64_encode($sec) . "\n", LOCK_EX);
        @chmod("$dir/$kid.sec", 0600);
        file_put_contents("$dir/$kid.pub", base64_encode($pub) . "\n", LOCK_EX);
        file_put_contents("$dir/current.txt", $kid . "\n", LOCK_EX);
        self::$cur = null;
        return $kid;
    }

    /** Barcha ochiq kalitlar: [['kid','alg','public'(base64url),'current'(bool)], ...]. */
    public static function publicKeys(): array
    {
        $cur = self::current()['kid'];
        $out = [];
        foreach (glob(self::dir() . '/*.pub') ?: [] as $f) {
            $kid = basename($f, '.pub');
            $pub = base64_decode(trim((string)file_get_contents($f)), true);
            if ($pub !== false && strlen($pub) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                $out[] = ['kid' => $kid, 'alg' => 'Ed25519', 'public' => Util::b64u($pub), 'current' => $kid === $cur];
            }
        }
        return $out;
    }

    public static function sign(string $type, array $payload): string
    {
        $k = self::current();
        $payload['kid'] = $k['kid'];
        $p = Util::b64u(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $sig = sodium_crypto_sign_detached($type . '.' . $p, $k['secret']);
        return $type . '.' . $p . '.' . Util::b64u($sig);
    }

    /** Imzoni tekshiradi va payloadni qaytaradi; muddatni tekshirmaydi. Aks holda null. */
    public static function verify(string $type, string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3 || $parts[0] !== $type) {
            return null;
        }
        $json = Util::b64uDecode($parts[1]);
        $sig = Util::b64uDecode($parts[2]);
        $payload = $json === false ? null : json_decode($json, true);
        if (!is_array($payload) || $sig === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return null;
        }
        $pubFile = self::dir() . '/' . preg_replace('/[^a-f0-9]/', '', (string)($payload['kid'] ?? '')) . '.pub';
        $pub = is_file($pubFile) ? base64_decode(trim((string)file_get_contents($pubFile)), true) : false;
        if ($pub === false || !sodium_crypto_sign_verify_detached($sig, $type . '.' . $parts[1], $pub)) {
            return null;
        }
        return $payload;
    }
}
