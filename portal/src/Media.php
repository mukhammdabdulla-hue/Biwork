<?php
declare(strict_types=1);

/**
 * Admin orqali yuklangan mahsulot rasmlari: data/uploads/tools/<id>/ (data papkasi har doim yoziladi va veb orqali ochilmaydi;
 * rasmlar /media/<id>/<fayl> orqali beriladi). GD bo'lsa rasm 1600 px gacha kichraytirilib webp ga o'tkaziladi (EXIF va
 * yashirin ma'lumotlar ham tushib qoladi), bo'lmasa o'zi saqlanadi (cheklov bilan).
 */
final class Media
{
    public const MAX_BYTES = 12 * 1024 * 1024;
    public const MAX_PIXELS = 16000000;
    public const MAX_W = 1600;
    private const FILE = '/^[a-z0-9][a-z0-9._-]{0,60}\.(webp|png|jpe?g)$/';
    private const MIME = ['webp' => 'image/webp', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg'];

    public static function dir(int $toolId): string
    {
        return Config::dataDir() . '/uploads/tools/' . $toolId;
    }

    public static function gd(): bool
    {
        return extension_loaded('gd') && function_exists('imagecreatetruecolor');
    }

    /** Mavjud fayl yo'li (yoki null). */
    public static function path(int $toolId, string $file): ?string
    {
        if ($toolId < 1 || preg_match(self::FILE, $file) !== 1) {
            return null;
        }
        $p = self::dir($toolId) . '/' . $file;
        return is_file($p) ? $p : null;
    }

    public static function url(int $toolId, string $file): string
    {
        $p = self::dir($toolId) . '/' . $file;
        return Util::url('/media/' . $toolId . '/' . $file) . '?v=' . substr((string)@filemtime($p), -6);
    }

    public static function mime(string $file): string
    {
        return self::MIME[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
    }

    /** Fayl nomlari ro'yxati (og.jpg dan tashqari). */
    public static function files(int $toolId): array
    {
        $out = [];
        foreach (glob(self::dir($toolId) . '/*') ?: [] as $f) {
            $n = basename($f);
            if ($n !== 'og.jpg' && preg_match(self::FILE, $n) === 1) {
                $out[] = $n;
            }
        }
        return $out;
    }

    /** Ko'p fayl maydonini ($_FILES['x'] bilan name[]) alohida yozuvlarga ajratadi; bo'sh tanlovlarni tashlaydi. */
    public static function normalize(?array $f): array
    {
        if (!$f || !isset($f['name'])) {
            return [];
        }
        if (!is_array($f['name'])) {
            return ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE ? [] : [$f];
        }
        $out = [];
        foreach (array_keys($f['name']) as $i) {
            if (($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $out[] = ['name' => $f['name'][$i], 'type' => $f['type'][$i] ?? '', 'tmp_name' => $f['tmp_name'][$i] ?? '', 'error' => $f['error'][$i], 'size' => $f['size'][$i] ?? 0];
        }
        return $out;
    }

    /** php.ini qiymati ("8M", "2G") bayt ko'rinishida; cheksiz bo'lsa PHP_INT_MAX. */
    public static function iniBytes(string $key): int
    {
        $v = trim((string)ini_get($key));
        if ($v === '' || $v === '-1' || $v === '0') {
            return PHP_INT_MAX;
        }
        $n = (int)$v;
        return match (strtolower(substr($v, -1))) { 'g' => $n * 1073741824, 'm' => $n * 1048576, 'k' => $n * 1024, default => $n };
    }

    private static function memory(): void
    {
        $bytes = self::iniBytes('memory_limit');
        if ($bytes < 268435456) {
            @ini_set('memory_limit', '256M');
        }
    }

    /** @return \GdImage|false */
    private static function load(string $path, int $type)
    {
        return match ($type) {
            IMAGETYPE_JPEG => function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($path) : false,
            IMAGETYPE_PNG => function_exists('imagecreatefrompng') ? @imagecreatefrompng($path) : false,
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default => false,
        };
    }

    /** Yuklangan rasmni tekshirib saqlaydi; saqlangan fayl nomini qaytaradi. */
    public static function store(int $toolId, array $f): string
    {
        $label = (string)($f['name'] ?? 'rasm');
        $err = (int)($f['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            throw new HttpError(422, "«{$label}»: rasm serverning yuklash chegarasidan katta (" . ini_get('upload_max_filesize') . "). Kichikroq rasm tanlang");
        }
        $tmp = (string)($f['tmp_name'] ?? '');
        if ($err !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) {
            throw new HttpError(422, "«{$label}» rasmini yuklab bo'lmadi, qayta urinib ko'ring");
        }
        if ((int)($f['size'] ?? 0) > self::MAX_BYTES) {
            throw new HttpError(422, "«{$label}»: rasm juda katta (12 MB dan oshmasin)");
        }
        $info = @getimagesize($tmp);
        if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            throw new HttpError(422, "«{$label}»: faqat PNG, JPG yoki WEBP rasm yuklang");
        }
        [$w, $h] = [(int)$info[0], (int)$info[1]];
        if ($w < 1 || $h < 1 || $w * $h > self::MAX_PIXELS) {
            throw new HttpError(422, "«{$label}»: rasm o'lchami juda katta ({$w}x{$h}). Ekran rasmini 4000 px dan kichik qiling");
        }
        $dir = self::dir($toolId);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new HttpError(500, "Rasm papkasini yaratib bo'lmadi (data papkasiga yozish ruxsati kerak)");
        }
        $base = 'ekran-' . bin2hex(random_bytes(4));
        $canWebp = self::gd() && function_exists('imagewebp');
        $src = self::gd() ? self::load($tmp, (int)$info[2]) : false;
        if ($src !== false) {
            self::memory();
            if (function_exists('imageistruecolor') && !imageistruecolor($src) && function_exists('imagepalettetotruecolor')) {
                imagepalettetotruecolor($src);
            }
            if ((int)$info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
                $ex = @exif_read_data($tmp);
                $rot = [3 => 180, 6 => -90, 8 => 90][(int)($ex['Orientation'] ?? 1)] ?? 0;
                if ($rot !== 0 && ($r = imagerotate($src, $rot, 0)) !== false) {
                    $src = $r;
                    [$w, $h] = [imagesx($src), imagesy($src)];
                }
            }
            if ($w > self::MAX_W) {
                $s = imagescale($src, self::MAX_W, -1, IMG_BICUBIC);
                if ($s !== false) {
                    $src = $s;
                }
            }
            imagealphablending($src, false);
            imagesavealpha($src, true);
            $name = $base . ($canWebp ? '.webp' : '.png');
            $ok = $canWebp ? @imagewebp($src, $dir . '/' . $name, 82) : @imagepng($src, $dir . '/' . $name, 7);
            if ($ok) {
                return $name;
            }
            @unlink($dir . '/' . $name);
        }
        // GD yo'q yoki o'qib bo'lmadi: o'zini saqlaymiz (kichik bo'lsa)
        if ((int)($f['size'] ?? filesize($tmp)) > 5 * 1024 * 1024) {
            throw new HttpError(422, "«{$label}»: rasm 5 MB dan katta, serverda uni kichraytirib bo'lmaydi. Kichikroq rasm tanlang");
        }
        $ext = [IMAGETYPE_JPEG => '.jpg', IMAGETYPE_PNG => '.png', IMAGETYPE_WEBP => '.webp'][(int)$info[2]];
        if (!@copy($tmp, $dir . '/' . $base . $ext)) {
            throw new HttpError(500, "Rasmni saqlab bo'lmadi");
        }
        return $base . $ext;
    }

    public static function remove(int $toolId, string $file): void
    {
        if (($p = self::path($toolId, $file)) !== null) {
            @unlink($p);
        }
    }

    /** Bundlangan (assets/products/<slug>) rasmlarni mahsulot papkasiga ko'chiradi: slug o'zgarganda rasmlar yo'qolmasin. */
    public static function adoptBundled(int $toolId, string $slug): void
    {
        $src = Marketing::assetDir($slug);
        if (!is_dir($src)) {
            return;
        }
        $dir = self::dir($toolId);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }
        foreach (glob($src . '/*') ?: [] as $f) {
            $n = basename($f);
            if (is_file($f) && preg_match(self::FILE, $n) === 1 && !is_file($dir . '/' . $n)) {
                @copy($f, $dir . '/' . $n);
            }
        }
    }

    /** Birinchi ekran rasmidan ulashish rasmini (1200x630, og.jpg) yasaydi: to'q ko'k fon, logo, ekran. Matn yo'q (sarlavha sahifadan olinadi). */
    public static function buildOg(int $toolId, string $shotPath): bool
    {
        if (!self::gd() || !function_exists('imagejpeg') || !is_file($shotPath)) {
            return false;
        }
        $info = @getimagesize($shotPath);
        $src = $info ? self::load($shotPath, (int)$info[2]) : false;
        if ($src === false) {
            return false;
        }
        self::memory();
        [$W, $H] = [1200, 630];
        $c = imagecreatetruecolor($W, $H);
        for ($y = 0; $y < $H; $y++) {
            $t = $y / $H;
            imageline($c, 0, $y, $W, $y, imagecolorallocate($c, (int)(4 + 9 * $t), (int)(28 + 20 * $t), (int)(49 + 28 * $t)));
        }
        $logoFile = BW_ROOT . '/public/assets/biwork-logo-white.png';
        $logo = function_exists('imagecreatefrompng') && is_file($logoFile) ? @imagecreatefrompng($logoFile) : false;
        if ($logo !== false) {
            $lh = 44;
            $lw = (int)round(imagesx($logo) * $lh / max(1, imagesy($logo)));
            imagealphablending($c, true);
            imagecopyresampled($c, $logo, 64, 44, 0, 0, $lw, $lh, imagesx($logo), imagesy($logo));
        }
        $sw = 1000;
        $sh = (int)round(imagesy($src) * $sw / max(1, imagesx($src)));
        $shot = imagescale($src, $sw, $sh, IMG_BICUBIC);
        if ($shot !== false) {
            $x = (int)(($W - $sw) / 2);
            $y = 124;
            $ch = min($sh, $H - $y - 32 + 40);
            for ($i = 10; $i >= 1; $i--) { // yumshoq soya
                imagefilledrectangle($c, $x - $i, $y + $i * 2, $x + $sw + $i, $y + $ch + $i * 2, imagecolorallocatealpha($c, 0, 0, 0, 118 + (int)($i * 0.8)));
            }
            imagecopy($c, $shot, $x, $y, 0, 0, $sw, $ch);
            imagerectangle($c, $x, $y, $x + $sw - 1, $y + $ch - 1, imagecolorallocatealpha($c, 255, 255, 255, 96));
        }
        $dir = self::dir($toolId);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }
        return @imagejpeg($c, $dir . '/og.jpg', 86);
    }
}
