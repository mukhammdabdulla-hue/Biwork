<?php
declare(strict_types=1);
/** Seed fayllari uchun umumiy yordamchilar (faqat XAYOLIY ma'lumot yaratish). */

/** Takrorlanadigan "tasodif": shablon har safar bir xil chiqadi. */
function bw_seed_rng(int $seed = 20261020): void { mt_srand($seed); }
function bw_rand(int $a, int $b): int { return mt_rand($a, $b); }
function bw_pick(array $list) { return $list[mt_rand(0, count($list) - 1)]; }
/** Bugundan $days kun oldingi (musbat) yoki keyingi (manfiy) sana, Y-m-d. */
function bw_date(int $days): string { return date('Y-m-d', strtotime(($days > 0 ? "-$days days" : ('+' . (-$days) . ' days')))); }
function bw_now_iso(int $daysAgo = 0): string { return gmdate('Y-m-d\TH:i:s\Z', time() - $daysAgo * 86400); }

/** Xayoliy o'zbek ismlari (haqiqiy shaxslar bilan bog'liq emas). */
const BW_ISM_ERKAK = ['Aziz', 'Bobur', 'Jasur', 'Sardor', 'Dilshod', 'Otabek', 'Rustam', 'Farrux', 'Behzod', 'Sherzod', 'Akmal', 'Umid', 'Nodir', 'Timur', 'Eldor', 'Javlon'];
const BW_ISM_AYOL = ['Dilnoza', 'Madina', 'Nigora', 'Malika', 'Gulnora', 'Sevara', 'Zilola', 'Feruza', 'Shahnoza', 'Munisa', 'Lola', 'Nargiza'];
const BW_FAMILIYA = ['Karimov', 'Rahimov', 'Yusupov', 'Tursunov', 'Ergashev', 'Nazarov', 'Saidov', 'Qodirov', 'Abdullayev', 'Mirzayev', 'Hasanov', 'Ismoilov', 'Olimov', 'Sobirov', 'Xolmatov'];
function bw_fio(bool $ayol = false): string {
    $f = bw_pick(BW_FAMILIYA);
    return ($ayol ? $f . 'a ' . bw_pick(BW_ISM_AYOL) : $f . ' ' . bw_pick(BW_ISM_ERKAK));
}
/** Yozuv identifikatori: <prefiks>_<tasodifiy 8 hex> (mijoz ham shunga o'xshash id beradi). */
function bw_id(string $prefix = 'x'): string { return $prefix . '_' . substr(bin2hex(random_bytes(6)), 0, 8); }
/** entity_yoz() ga qisqa o'rama: (tur, id, ma'lumot) */
function bw_put(string $type, string $id, array $data): void {
    $data['id'] = $data['id'] ?? $id;
    entity_yoz($type, $id, $data, 'namuna-admin');
}
