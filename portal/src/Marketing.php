<?php
declare(strict_types=1);

/**
 * Ochiq sayt (reklama vitrinasi): mahsulot reklama ma'lumotini tozalash, rasmlar, ulashish rasmi, reklama manbasi (utm).
 * Manifestdagi `marketing` bo'limi shartnomasi: docs/TOOL_CONTRACT.md §1.
 */
final class Marketing
{
    private const IMG = '/^[a-z0-9][a-z0-9._-]{0,60}\.(webp|png|jpe?g)$/';
    private const UTM = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'];
    private const COOKIE = 'bw_src';

    /** Mahsulot rangi (urg'u): kalit => o'zbekcha nom. Ranglar site.css dagi [data-accent="kalit"] bloklarida. */
    public const ACCENTS = ['emerald' => 'Zumrad', 'blue' => 'Moviy', 'violet' => 'Binafsha', 'orange' => "To'q sariq", 'cyan' => 'Firuza', 'rose' => 'Atirgul', 'slate' => "Kulrang-ko'k"];
    /** Mahsulot sahifasi tepa qismining joylashuvi (site.css dagi [data-hero="kalit"]). */
    public const HEROES = ['split' => "Matn chapda, rasm o'ngda", 'rev' => "Rasm chapda, matn o'ngda", 'center' => 'Matn o\'rtada, rasm pastda', 'bleed' => "Rasm o'ng chetgacha cho'zilgan", 'duo' => 'Ikkita rasm ustma-ust'];
    /** Tepa qism foni (site.css dagi [data-tone="kalit"]). */
    public const TONES = ['dark' => "To'q fon", 'light' => 'Och fon'];
    /** Admin tanlamagan bo'lsa, tayyor mahsulotlarning boshlang'ich ko'rinishi: [rang, joylashuv, fon]. */
    private const LOOKS = [
        'reja' => ['emerald', 'duo', 'dark'], 'hr' => ['blue', 'split', 'light'], 'loyiha' => ['violet', 'center', 'dark'], 'savdo' => ['orange', 'rev', 'light'],
        'moliya' => ['cyan', 'bleed', 'dark'], 'yuridik' => ['rose', 'rev', 'dark'], 'monitoring' => ['slate', 'split', 'dark'],
    ];

    /**
     * Mahsulotning ko'rinishi: ['accent' => rang, 'hero' => joylashuv, 'tone' => fon]. Admin tanlagani ustun; tanlamagan bo'lsa tayyor
     * mahsulot uchun yuqoridagi jadvaldan, yangi mahsulot uchun manzilidan barqaror hisoblanadi (har biri o'z rangiga ega bo'ladi).
     * @param array $tool mahsulot qatori (slug, marketing)
     */
    public static function theme(array $tool): array
    {
        $slug = (string)($tool['slug'] ?? '');
        $own = is_array($tool['marketing']['theme'] ?? null) ? $tool['marketing']['theme'] : [];
        $def = self::LOOKS[$slug] ?? null;
        if ($def === null) {
            $keys = array_keys(self::ACCENTS);
            $h = $slug === '' ? 0 : crc32($slug);
            $def = [$keys[$h % (count($keys) - 1)], array_keys(self::HEROES)[($h >> 4) % count(self::HEROES)], (($h >> 9) & 1) ? 'light' : 'dark'];
        }
        $pick = static fn(string $k, array $allowed, string $fallback): string => is_string($own[$k] ?? null) && isset($allowed[$own[$k]]) ? $own[$k] : $fallback;
        return ['accent' => $pick('accent', self::ACCENTS, $def[0]), 'hero' => $pick('hero', self::HEROES, $def[1]), 'tone' => $pick('tone', self::TONES, $def[2])];
    }

    // ------------------------------------------------------------------ manifestdagi marketing bo'limini tozalash

    private static function str(mixed $v, int $max): string
    {
        return is_scalar($v) ? trim(mb_substr(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', (string)$v) ?? '', 0, $max)) : '';
    }

    /** @return list<array<string,string>> */
    private static function pairs(mixed $list, string $a, string $b, int $maxA, int $maxB, int $limit): array
    {
        $out = [];
        foreach (is_array($list) ? array_values($list) : [] as $it) {
            $x = self::str($it[$a] ?? null, $maxA);
            if ($x !== '' && count($out) < $limit) {
                $out[] = [$a => $x, $b => self::str($it[$b] ?? null, $maxB)];
            }
        }
        return $out;
    }

    public static function img(mixed $f): string
    {
        return is_string($f) && preg_match(self::IMG, $f) === 1 ? $f : '';
    }

    /** Faqat ma'lum maydonlar, uzunlik chegaralari bilan; begona kalitlar tashlanadi. */
    public static function clean(array $m): array
    {
        $o = ['headline' => self::str($m['headline'] ?? '', 160), 'lead' => self::str($m['lead'] ?? '', 700)];
        $o['badges'] = array_values(array_filter(array_map(static fn($x) => self::str($x, 50), array_slice(is_array($m['badges'] ?? null) ? array_values($m['badges']) : [], 0, 6))));
        $o['audience'] = self::pairs($m['audience'] ?? [], 'title', 'text', 80, 300, 6);
        $o['problems'] = self::pairs($m['problems'] ?? [], 'title', 'text', 90, 320, 6);
        $o['features'] = [];
        foreach (array_slice(is_array($m['features'] ?? null) ? array_values($m['features']) : [], 0, 8) as $f) {
            $t = self::str($f['title'] ?? '', 90);
            if ($t === '') {
                continue;
            }
            $o['features'][] = [
                'title' => $t, 'text' => self::str($f['text'] ?? '', 600), 'shot' => self::img($f['shot'] ?? ''),
                'points' => array_values(array_filter(array_map(static fn($x) => self::str($x, 170), array_slice(is_array($f['points'] ?? null) ? array_values($f['points']) : [], 0, 5)))),
            ];
        }
        $o['shots'] = [];
        foreach (array_slice(is_array($m['shots'] ?? null) ? array_values($m['shots']) : [], 0, 12) as $s) {
            $file = self::img($s['file'] ?? '');
            if ($file !== '') {
                $o['shots'][] = ['file' => $file, 'caption' => self::str($s['caption'] ?? '', 170), 'alt' => self::str($s['alt'] ?? ($s['caption'] ?? ''), 220)];
            }
        }
        $o['faq'] = self::pairs($m['faq'] ?? [], 'q', 'a', 220, 900, 10);
        // «Qanday ishlatiladi»: qadamma-qadam ko'rsatma (har qadamda ixtiyoriy ekran rasmi va qisqa punktlar)
        $g = is_array($m['guide'] ?? null) ? $m['guide'] : [];
        $o['guide'] = ['intro' => self::str($g['intro'] ?? '', 400), 'steps' => [], 'tips' => array_values(array_filter(array_map(static fn($x) => self::str($x, 220), array_slice(is_array($g['tips'] ?? null) ? array_values($g['tips']) : [], 0, 6))))];
        foreach (array_slice(is_array($g['steps'] ?? null) ? array_values($g['steps']) : [], 0, 8) as $st) {
            $t = self::str($st['title'] ?? '', 90);
            if ($t !== '') {
                $o['guide']['steps'][] = [
                    'title' => $t, 'text' => self::str($st['text'] ?? '', 600), 'shot' => self::img($st['shot'] ?? ''),
                    'points' => array_values(array_filter(array_map(static fn($x) => self::str($x, 170), array_slice(is_array($st['points'] ?? null) ? array_values($st['points']) : [], 0, 4)))),
                ];
            }
        }
        $seo = is_array($m['seo'] ?? null) ? $m['seo'] : [];
        $o['seo'] = ['title' => self::str($seo['title'] ?? '', 70), 'description' => self::str($seo['description'] ?? '', 180),
            'keywords' => array_values(array_filter(array_map(static fn($x) => self::str($x, 40), array_slice(is_array($seo['keywords'] ?? null) ? array_values($seo['keywords']) : [], 0, 12))))];
        $th = is_array($m['theme'] ?? null) ? $m['theme'] : [];
        $o['theme'] = [];
        foreach (['accent' => self::ACCENTS, 'hero' => self::HEROES, 'tone' => self::TONES] as $k => $allowed) {
            $o['theme'][$k] = is_string($th[$k] ?? null) && isset($allowed[$th[$k]]) ? $th[$k] : '';
        }
        $cta = is_array($m['cta'] ?? null) ? $m['cta'] : [];
        $o['cta'] = ['trial' => self::str($cta['trial'] ?? '', 40), 'lead' => self::str($cta['lead'] ?? '', 40),
            'lead_title' => self::str($cta['lead_title'] ?? '', 90), 'lead_text' => self::str($cta['lead_text'] ?? '', 260),
            'placeholder' => self::str($cta['placeholder'] ?? '', 140)];
        return $o;
    }

    // ------------------------------------------------------------------ rasmlar

    public static function assetDir(string $slug): string
    {
        return BW_ROOT . '/public/assets/products/' . $slug;
    }

    /**
     * Ekran rasmi: avval admin yuklagan (data/uploads/tools/<id>), so'ng paket bilan kelgan (assets/products/<slug>).
     * @param array|string $tool mahsulot qatori (id, slug) yoki faqat slug
     * @return ?array{url:string,w:int,h:int}
     */
    public static function shot(array|string $tool, string $file): ?array
    {
        $slug = is_array($tool) ? (string)($tool['slug'] ?? '') : $tool;
        $id = is_array($tool) ? (int)($tool['id'] ?? 0) : 0;
        if (self::img($file) === '') {
            return null;
        }
        if ($id > 0 && ($up = Media::path($id, $file)) !== null && ($sz = @getimagesize($up))) {
            return ['url' => Media::url($id, $file), 'w' => (int)$sz[0], 'h' => (int)$sz[1]];
        }
        if (!preg_match('/^[a-z0-9-]+$/', $slug)) {
            return null;
        }
        $path = self::assetDir($slug) . '/' . $file;
        $sz = is_file($path) ? @getimagesize($path) : false;
        return $sz ? ['url' => Util::url('/assets/products/' . $slug . '/' . $file) . '?v=' . substr((string)filemtime($path), -6), 'w' => (int)$sz[0], 'h' => (int)$sz[1]] : null;
    }

    /** Ekran rasmi diskda bormi (admin yuklagan yoki paket bilan kelgan). */
    public static function hasShot(array $tool, string $file): bool
    {
        return self::shot($tool, $file) !== null;
    }

    /** Ulashish rasmi (1200x630): paket bilan kelgan og.png, so'ng admin saqlaganda yasalgan og.jpg, aks holda umumiy. Mutlaq manzil. */
    public static function ogImage(array|string|null $tool = null): string
    {
        $slug = is_array($tool) ? (string)($tool['slug'] ?? '') : (string)$tool;
        $id = is_array($tool) ? (int)($tool['id'] ?? 0) : 0;
        if ($slug !== '' && preg_match('/^[a-z0-9-]+$/', $slug) && is_file(self::assetDir($slug) . '/og.png')) {
            return Util::absUrl('/assets/products/' . $slug . '/og.png');
        }
        if ($id > 0 && is_file(Media::dir($id) . '/og.jpg')) {
            return Util::absUrl('/media/' . $id . '/og.jpg') . '?v=' . substr((string)@filemtime(Media::dir($id) . '/og.jpg'), -6);
        }
        return Util::absUrl('/assets/og-default.png');
    }

    // ------------------------------------------------------------------ admin formasi <-> marketing

    /** @return list<string> bo'sh bo'lmagan qatorlar */
    public static function lines(mixed $s, int $max = 200): array
    {
        $out = [];
        foreach (preg_split('/\R/u', is_string($s) ? $s : '') ?: [] as $ln) {
            $ln = trim($ln);
            if ($ln !== '' && count($out) < $max) {
                $out[] = $ln;
            }
        }
        return $out;
    }

    /**
     * Admin formasidagi maydonlardan marketing bo'limini yig'adi (clean() dan o'tkaziladi; tartib — formadagi tartib).
     * Rasmlar: `shots[i][file|caption|alt|remove]`; yangi yuklangan rasmlarni chaqiruvchi qo'shadi.
     */
    public static function fromForm(array $p): array
    {
        $rows = static function (string $k) use ($p): array {
            $r = $p[$k] ?? null;
            return is_array($r) ? array_values(array_filter($r, 'is_array')) : [];
        };
        $m = [
            'headline' => $p['headline'] ?? '', 'lead' => $p['lead'] ?? '',
            'badges' => self::lines($p['badges'] ?? '', 6),
            'problems' => $rows('problems'), 'audience' => $rows('audience'), 'faq' => $rows('faq'),
            'features' => array_map(static fn($f) => ['title' => $f['title'] ?? '', 'text' => $f['text'] ?? '', 'shot' => $f['shot'] ?? '', 'points' => self::lines($f['points'] ?? '', 5)], $rows('features')),
            'shots' => [],
        ];
        foreach ($rows('shots') as $s) {
            if (empty($s['remove'])) {
                $m['shots'][] = ['file' => $s['file'] ?? '', 'caption' => $s['caption'] ?? '', 'alt' => ($s['alt'] ?? '') !== '' ? $s['alt'] : ($s['caption'] ?? '')];
            }
        }
        $g = is_array($p['guide'] ?? null) ? $p['guide'] : [];
        $gs = is_array($g['steps'] ?? null) ? array_values(array_filter($g['steps'], 'is_array')) : [];
        $m['guide'] = [
            'intro' => $g['intro'] ?? '', 'tips' => self::lines($g['tips'] ?? '', 6),
            'steps' => array_map(static fn($st) => ['title' => $st['title'] ?? '', 'text' => $st['text'] ?? '', 'shot' => $st['shot'] ?? '', 'points' => self::lines($st['points'] ?? '', 4)], $gs),
        ];
        $seo = is_array($p['seo'] ?? null) ? $p['seo'] : [];
        $m['seo'] = ['title' => $seo['title'] ?? '', 'description' => $seo['description'] ?? '',
            'keywords' => array_map('trim', explode(',', is_string($seo['keywords'] ?? null) ? $seo['keywords'] : ''))];
        $m['cta'] = is_array($p['cta'] ?? null) ? $p['cta'] : [];
        $m['theme'] = is_array($p['theme'] ?? null) ? $p['theme'] : [];
        return $m;
    }

    // ------------------------------------------------------------------ reklama manbasi

    /** Reklama havolasidagi ?utm_* ni 30 kunlik cookie ga yozadi (murojaat/ro'yxatdan o'tishda manba ma'lum bo'lsin). */
    public static function captureSource(): void
    {
        $pairs = [];
        foreach (self::UTM as $k) {
            $v = $_GET[$k] ?? null;
            if (is_string($v) && preg_match('/^[A-Za-z0-9._\- ]{1,40}$/', $v)) {
                $pairs[] = $k . '=' . $v;
            }
        }
        if (!$pairs || headers_sent()) {
            return;
        }
        $val = implode('&', $pairs);
        @setcookie(self::COOKIE, $val, ['expires' => time() + 30 * 86400, 'path' => Util::basePath() ?: '/', 'secure' => Auth::isHttps(), 'httponly' => true, 'samesite' => 'Lax']);
        $_COOKIE[self::COOKIE] = $val;
    }

    /** "utm_source=facebook&utm_campaign=bahor" (+ tashqi sayt nomi) — murojaat/korxona yozuviga. */
    public static function source(): string
    {
        $c = $_COOKIE[self::COOKIE] ?? '';
        $s = is_string($c) && preg_match('/^[A-Za-z0-9._\- =&]{0,260}$/', $c) ? $c : '';
        $ref = (string)parse_url((string)($_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_HOST);
        $self = (string)parse_url(Util::absUrl('/'), PHP_URL_HOST);
        if ($s === '' && $ref !== '' && strcasecmp($ref, $self) !== 0 && preg_match('/^[A-Za-z0-9.\-]{1,80}$/', $ref)) {
            $s = 'ref=' . $ref;
        }
        return $s;
    }
}
