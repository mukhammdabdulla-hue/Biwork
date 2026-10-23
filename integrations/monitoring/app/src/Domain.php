<?php
declare(strict_types=1);

/** Sohaga oid doimiylar: bo'limlar, to'xtash sabablari, holatlar, namuna korxona uskunalari. */
final class Domain
{
    /** Bo'lim = operatsiya turi (uskuna shu bo'limga tegishli). */
    public const SECTIONS = [
        'kesish' => 'Kesish',
        'bosma' => 'Bosma',
        'lam' => 'Laminatsiya',
        'tigel' => 'Tigel va shtanz',
        'yelim' => 'Yelimlash',
    ];

    public const REASONS = [
        'tamir' => "Ta'mir",
        'material' => 'Material kutilmoqda',
        'qolip' => 'Qolip kutilmoqda',
        'tozalash' => 'Tozalash',
        'boshqa_uchastka' => 'Boshqa uchastkada ishlagan',
        'tanaffus' => 'Tanaffus',
        'ish_yoq' => "Ish yo'q",
        'boshqa' => 'Sababi yozilmagan',
    ];

    public const STATES = [
        'run' => 'Ishlayapti',
        'setup' => 'Sozlanmoqda',
        'stop' => "To'xtagan",
        'wait' => 'Kutmoqda',
        'idle' => "Bo'sh",
    ];

    public const SEVERITY = ['crit' => 'Jiddiy', 'warn' => 'Diqqat', 'info' => "Ma'lumot"];
    public const SEV_ORDER = ['crit' => 0, 'warn' => 1, 'info' => 2];

    public const FAMILIES = [
        'toxtash' => "To'xtashlar",
        'rejafakt' => 'Reja va fakt',
        'buyurtma' => 'Buyurtma muddati',
        'navbat' => 'Navbat',
        'malumot' => "Ma'lumot sifati",
    ];

    /** Operatsiyalar orasidagi texnologik tanaffus (daqiqa): bosmadan keyin bo'yoq quriydi. */
    public const GAP_AFTER = ['bosma' => 120];

    /**
     * Namuna korxona uskunalari: id => [nom, bo'lim, tezlik (varaq/soat), sozlash (daqiqa)].
     * Tezlik va sozlash vaqtlari haqiqiy tigel/bosma ma'lumotlaridan o'rganilgan o'rtachalarga yaqin.
     */
    public const MACHINES = [
        'list' => ['Listorez', 'kesish', 2900, 18],
        'ofs105' => ['Ofset 105', 'bosma', 2500, 30],
        'ofs72' => ['Ofset 72', 'bosma', 800, 100],
        'lam920' => ['Laminator 920', 'lam', 660, 36],
        'lam630' => ['Laminator 630', 'lam', 400, 36],
        'tg03' => ['Tigel-03', 'tigel', 590, 45],
        'tg04' => ['Tigel-04', 'tigel', 490, 43],
        'tg05' => ['Tigel-05', 'tigel', 520, 45],
        'tg07' => ['Tigel-07', 'tigel', 390, 51],
        'tg08' => ['Tigel-08', 'tigel', 460, 42],
        'shk' => ['Shtanz karton', 'tigel', 940, 68],
        'shg' => ['Shtanz gofra', 'tigel', 1370, 50],
        'ye2500' => ['Yelimlash 2500', 'yelim', 1650, 12],
        'ye850' => ['Yelimlash 850', 'yelim', 1750, 21],
    ];

    public const PEOPLE = [
        ['Shavkat Karimov', "Smena boshlig'i"],
        ['Dilshod Aliyev', "Ta'mirchi"],
        ['Malika Rahimova', 'Rejalashtiruvchi'],
        ['Rustam Yusupov', "Sex boshlig'i"],
    ];

    public static function stateLabel(string $s): string
    {
        return self::STATES[$s] ?? $s;
    }

    public static function reasonLabel(?string $r): string
    {
        return $r === null || $r === '' ? self::REASONS['boshqa'] : (self::REASONS[$r] ?? $r);
    }
}
