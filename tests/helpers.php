<?php
declare(strict_types=1);

/** Sinovlar uchun umumiy yordamchilar: ok(), Browser (cookie bilan HTTP mijoz), api(). */

function ok(bool $c, string $n): void
{
    global $fails, $total;
    $total++;
    echo ($c ? '  ✓ ' : '  ✗ ') . $n . "\n";
    if (!$c) {
        $fails++;
    }
}


/** Brauzer: cookie saqlaydi, redirectga ergashmaydi (o'zimiz tekshiramiz). */
final class Browser
{
    private string $jar;
    public function __construct(public string $base)
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'bwjar');
    }
    /** @return array{status:int, body:string, headers:array, location:?string} */
    public function req(string $method, string $path, array $form = [], array $opts = []): array
    {
        $ch = curl_init(str_starts_with($path, 'http') ? $path : $this->base . $path);
        $hdr = [];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_TIMEOUT => 20,
            CURLOPT_HEADERFUNCTION => static function ($c, $h) use (&$hdr) {
                if (str_contains($h, ':')) {
                    [$k, $v] = explode(':', $h, 2);
                    $hdr[strtolower(trim($k))] = trim($v);
                }
                return strlen($h);
            },
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $opts['multipart'] ?? false ? $form : http_build_query($form));
        }
        if (!empty($opts['json'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($opts['json']));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        }
        $body = (string)curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['status' => $status, 'body' => $body, 'headers' => $hdr, 'location' => $hdr['location'] ?? null];
    }
    public function get(string $p): array { return $this->req('GET', $p); }
    public function csrf(string $page): string
    {
        $r = $this->get($page);
        preg_match('/name="csrf" value="([a-f0-9]+)"/', $r['body'], $m);
        return $m[1] ?? '';
    }
    /** CSRF ni $page dan olib, shaklni yuboradi. */
    public function post(string $page, string $action, array $form = []): array
    {
        return $this->req('POST', $action, ['csrf' => $this->csrf($page)] + $form);
    }
    public function follow(array $r): array
    {
        $loc = $r['location'] ?? null;
        return $loc ? $this->get(str_starts_with($loc, 'http') ? $loc : $loc) : $r;
    }
}

function api(string $base, string $op, array $body): array
{
    $b = new Browser($base);
    $r = $b->req('POST', '/api/v1/licenses/' . $op, [], ['json' => $body]);
    return [$r['status'], json_decode($r['body'], true) ?: []];
}

