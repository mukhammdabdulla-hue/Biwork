<?php
declare(strict_types=1);

/**
 * Qoidalar ro'yxati: har qoidaning nomi, tushuntirishi va sozlanadigan chegaralari.
 * Chegaralar bazada (rules.params) saqlanadi; berilmagan bo'lsa shu yerdagi standart ishlatiladi.
 * Tekshiruvning o'zi Engine'da.
 */
final class Rules
{
    /** param: [kalit => [yorliq, birlik, standart, min, max]] */
    public const DEFS = [
        'stop_long' => [
            'family' => 'toxtash', 'name' => "Uskuna uzoq to'xtab turibdi",
            'text' => "Uskuna belgilangan vaqtdan uzoq to'xtab tursa ogohlantiradi. Tanaffus va «ish yo'q» hisoblanmaydi.",
            'params' => [
                'minutes' => ["To'xtashdan keyin ogohlantirish", 'daqiqa', 60, 10, 600],
                'crit_factor' => ['Jiddiy deb belgilash', 'baravar uzoq', 3, 1.5, 10],
            ],
        ],
        'stop_repeat' => [
            'family' => 'toxtash', 'name' => 'Uskuna tez-tez buziladi',
            'text' => "Bir uskuna qisqa vaqt ichida bir necha marta ta'mirga to'xtasa ogohlantiradi — odatda asosiy nosozlik tuzatilmagan bo'ladi.",
            'params' => [
                'count' => ["Buzilishlar soni", 'marta', 3, 2, 10],
                'days' => ['Necha kun ichida', 'kun', 7, 2, 30],
                'crit_count' => ['Jiddiy deb belgilash', 'marta', 5, 3, 20],
            ],
        ],
        'idle_ready' => [
            'family' => 'rejafakt', 'name' => "Uskuna bo'sh, ish tayyor",
            'text' => "Uskuna ishlamayapti, navbatda esa tayyor buyurtma kutib turibdi — odam, material yoki qolip yetishmayotgan bo'lishi mumkin.",
            'params' => [
                'minutes' => ['Bo\'sh turganidan keyin ogohlantirish', 'daqiqa', 30, 10, 240],
                'crit_factor' => ['Jiddiy deb belgilash', 'baravar uzoq', 4, 1.5, 10],
            ],
        ],
        'start_late' => [
            'family' => 'rejafakt', 'name' => 'Ish rejadagi vaqtda boshlanmadi',
            'text' => "Rejada boshlanishi kerak bo'lgan ish kechiksa ogohlantiradi va sababini aytadi: uskuna to'xtagan, oldingi ish cho'zilgan yoki oldingi bosqich tugamagan.",
            'params' => [
                'minutes' => ['Kechikishdan keyin ogohlantirish', 'daqiqa', 45, 15, 480],
                'crit_minutes' => ['Jiddiy deb belgilash', 'daqiqa', 180, 30, 1440],
            ],
        ],
        'late_risk' => [
            'family' => 'buyurtma', 'name' => 'Buyurtma muddatga ulgurmasligi mumkin',
            'text' => "Har buyurtmaning qolgan ishlari va uskunalardagi haqiqiy kechikishdan tugash vaqti taxmin qilinadi va muddat bilan solishtiriladi.",
            'params' => [
                'buffer_h' => ["Muddatgacha kamida zaxira", 'soat', 4, 0, 48],
            ],
        ],
        'setup_long' => [
            'family' => 'rejafakt', 'name' => "Sozlash me'yordan ko'p ketyapti",
            'text' => "Uskunani sozlash me'yordagi vaqtdan necha baravar oshsa ogohlantiradi.",
            'params' => [
                'factor' => ["Me'yordan oshganda", 'baravar', 1.5, 1.1, 5],
                'min_minutes' => ['Kamida', 'daqiqa', 20, 5, 120],
            ],
        ],
        'overrun' => [
            'family' => 'rejafakt', 'name' => "Ish me'yor vaqtidan oshib ketdi",
            'text' => "Buyurtma uskunada me'yordan ko'p ishlagan, lekin hali tugamagan bo'lsa ogohlantiradi: tezlik pasaygan bo'lishi mumkin.",
            'params' => [
                'factor' => ["Me'yordan oshganda", 'baravar', 1.3, 1.1, 3],
                'min_minutes' => ['Kamida', 'daqiqa', 30, 10, 240],
            ],
        ],
        'queue_overload' => [
            'family' => 'navbat', 'name' => "Uskuna oldida ish yig'ilib qoldi",
            'text' => "Uskuna oldidagi ish hajmi (soatda) belgilangandan oshsa — bu tor joy: boshqa buyurtmalar shu yerda kutib qoladi.",
            'params' => [
                'hours' => ['Navbat hajmi', 'soat', 48, 4, 200],
                'crit_factor' => ['Jiddiy deb belgilash', 'baravar ko\'p', 1.5, 1.1, 5],
            ],
        ],
        'no_reason' => [
            'family' => 'malumot', 'name' => "To'xtash sababi yozilmagan",
            'text' => "To'xtash sababi jurnalga yozilmasa, uni keyin tahlil qilib bo'lmaydi. Bunday to'xtashlar ko'payganda eslatadi.",
            'params' => [
                'minutes' => ["To'xtash kamida", 'daqiqa', 20, 5, 120],
                'count' => ['Oxirgi 24 soatda', 'ta', 2, 1, 10],
            ],
        ],
        'source_stale' => [
            'family' => 'malumot', 'name' => 'Ma\'lumot yangilanmagan',
            'text' => "Hisobot yoki fayl odatdagidan uzoq yangilanmasa ogohlantiradi: eskirgan ma'lumot bilan boshqa ogohlantirishlar ishonchsiz bo'ladi.",
            'params' => [
                'factor' => ['Odatdagi vaqtdan oshganda', 'baravar', 1.5, 1, 10],
                'crit_factor' => ['Jiddiy deb belgilash', 'baravar', 3, 1.5, 20],
            ],
        ],
    ];

    /** Umumiy sozlamalar (qoida emas). */
    public const GENERAL = [
        'snooze_min' => ["Yopilgan ogohlantirishni qayta ochmaslik", 'daqiqa', 240, 0, 1440],
        'stop_extra_min' => ["Prognozda to'xtashga qo'shimcha vaqt", 'daqiqa', 60, 0, 480],
    ];

    /** @return array<string,array{enabled:bool,params:array<string,float|int>}> */
    public static function load(): array
    {
        $rows = [];
        foreach (Db::all('SELECT code, enabled, params FROM rules') as $r) {
            $rows[$r['code']] = $r;
        }
        $out = [];
        foreach (self::DEFS as $code => $d) {
            $saved = isset($rows[$code]) ? (json_decode((string)$rows[$code]['params'], true) ?: []) : [];
            $out[$code] = ['enabled' => !isset($rows[$code]) || (int)$rows[$code]['enabled'] === 1, 'params' => self::merge($d['params'], $saved)];
        }
        $saved = isset($rows['general']) ? (json_decode((string)$rows['general']['params'], true) ?: []) : [];
        $out['general'] = ['enabled' => true, 'params' => self::merge(self::GENERAL, $saved)];
        return $out;
    }

    private static function merge(array $defs, array $saved): array
    {
        $p = [];
        foreach ($defs as $k => $d) {
            $v = $saved[$k] ?? $d[2];
            $p[$k] = is_numeric($v) ? $v + 0 : $d[2];
        }
        return $p;
    }

    /** Qoidani saqlash: faqat ma'lum kalitlar, chegaralar ichida. */
    public static function save(string $code, ?bool $enabled, array $params): void
    {
        $defs = $code === 'general' ? self::GENERAL : (self::DEFS[$code]['params'] ?? null);
        if ($defs === null) {
            throw new ApiError(404, 'not_found', 'Bunday qoida yo\'q');
        }
        $cur = self::load()[$code];
        $p = $cur['params'];
        foreach ($params as $k => $v) {
            if (!isset($defs[$k])) {
                continue;
            }
            if (!is_numeric($v)) {
                throw new ApiError(422, 'bad_value', "«{$defs[$k][0]}» uchun raqam kiriting");
            }
            [$label, , , $min, $max] = $defs[$k];
            $v = $v + 0;
            if ($v < $min || $v > $max) {
                throw new ApiError(422, 'bad_value', "«{$label}» $min dan $max gacha bo'lishi kerak");
            }
            $p[$k] = $v;
        }
        Db::exec('INSERT INTO rules (code, enabled, params) VALUES (?, ?, ?) ON CONFLICT(code) DO UPDATE SET enabled = excluded.enabled, params = excluded.params',
            [$code, ($enabled ?? $cur['enabled']) ? 1 : 0, json_encode($p)]);
    }

    /** Interfeys uchun to'liq ko'rinish. */
    public static function describe(): array
    {
        $cfg = self::load();
        $open = [];
        foreach (Db::all("SELECT rule, COUNT(*) c FROM signals WHERE status IN ('open','seen') GROUP BY rule") as $r) {
            $open[$r['rule']] = (int)$r['c'];
        }
        $out = [];
        foreach (self::DEFS as $code => $d) {
            $out[] = [
                'code' => $code, 'family' => $d['family'], 'family_name' => Domain::FAMILIES[$d['family']], 'name' => $d['name'], 'text' => $d['text'],
                'enabled' => $cfg[$code]['enabled'], 'open' => $open[$code] ?? 0, 'params' => self::paramList($d['params'], $cfg[$code]['params']),
            ];
        }
        return ['rules' => $out, 'general' => self::paramList(self::GENERAL, $cfg['general']['params'])];
    }

    private static function paramList(array $defs, array $vals): array
    {
        $l = [];
        foreach ($defs as $k => [$label, $unit, $def, $min, $max]) {
            $l[] = ['key' => $k, 'label' => $label, 'unit' => $unit, 'value' => $vals[$k], 'default' => $def, 'min' => $min, 'max' => $max];
        }
        return $l;
    }
}
