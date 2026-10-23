<?php
/**
 * Biwork HR namunasi — xodimlar ro'yxati (butunlay xayoliy: "Zamin Plast MChJ" va uning odamlari).
 * Qaytaradi: [ [familiya, ismi, sharifi, bo'lim, lavozim, ishSana, tug'ilgan, oylik(so'm), xususiyat], ... ]
 * `ishSana`/`tug'ilgan` — Y-m-d yoki ['kun' => N] (bugundan N kun oldin / -N kun keyin).
 * `xususiyat`: ofis (true — ofis), intizom (-1..2: kechikishga moyillik), ip (ichki raqam), tel/mail/manzil (yo'q bo'lsa avto).
 */

const HR_B_BOSH = 'Boshqaruv';
const HR_B_KADR = "Ma'muriyat va kadrlar";
const HR_B_BUX = 'Buxgalteriya';
const HR_B_SAVDO = 'Savdo va marketing';
const HR_B_ISH = 'Ishlab chiqarish';
const HR_B_OMB = 'Ombor va logistika';

/** Bugundan $n kun OLDINGI sana */
function hr_kun(int $n): string { return bw_date($n); }

/** Tug'ilgan kun: bugundan $n kun KEYIN tug'ilgan kuni bo'ladigan odam ($yil — tug'ilgan yili) */
function hr_tugilgan_yaqin(int $yil, int $n): string {
    $t = strtotime('+' . $n . ' days');
    return sprintf('%04d-%02d-%02d', $yil, (int)date('n', $t), (int)date('j', $t));
}

function hr_xodimlar_royxati(): array
{
    return [
        // ---------------- Boshqaruv
        ['Rustamov', 'Alisher', 'Bahodirovich', HR_B_BOSH, 'Bosh direktor', '2014-03-10', '1976-04-18', 28000000, ['ofis' => true, 'ip' => '1001', 'intizom' => 0, 'kelish' => -22]],
        ['Xolmatova', 'Dilfuza', 'Anvarovna', HR_B_BOSH, "Direktor o'rinbosari (tijorat)", '2016-09-01', '1980-11-02', 19500000, ['ofis' => true, 'ip' => '1002', 'intizom' => 0, 'kelish' => -15]],
        ['Ergashev', 'Sanjar', 'Ravshanovich', HR_B_BOSH, "Direktor o'rinbosari (ishlab chiqarish)", '2017-02-13', '1979-07-23', 19500000, ['ofis' => true, 'ip' => '1003', 'intizom' => 0, 'kelish' => -25]],
        ['Mirzayeva', 'Nigora', 'Sobirovna', HR_B_BOSH, 'Kotib-referent', '2021-05-17', '1994-02-14', 6200000, ['ofis' => true, 'ip' => '1000', 'intizom' => 0, 'kelish' => -10]],

        // ---------------- Ma'muriyat va kadrlar
        ['Yusupova', 'Malika', 'Akmalovna', HR_B_KADR, "Xodimlar bo'yicha menejer", '2018-04-02', '1988-09-30', 11800000, ['ofis' => true, 'ip' => '1010', 'intizom' => 0, 'kelish' => -14]],
        ['Qodirov', 'Behzod', "Ilhom o'g'li", HR_B_KADR, 'Kadrlar inspektori', '2022-01-10', '1992-12-05', 7400000, ['ofis' => true, 'ip' => '1011', 'intizom' => 1, 'kelish' => -8]],
        ['Saidov', 'Otabek', "Murod o'g'li", HR_B_KADR, 'Tizim administratori (IT)', '2020-08-24', '1991-03-17', 10500000, ['ofis' => true, 'ip' => '1012', 'intizom' => 0, 'kelish' => -6]],
        ['Nazarov', 'Eldor', "Rustam o'g'li", HR_B_KADR, "Xaridlar bo'yicha mutaxassis", '2023-03-06', '1995-06-21', 7900000, ['ofis' => true, 'ip' => '1013', 'intizom' => 1, 'kelish' => -5]],
        ['Tursunova', 'Zilola', 'Baxtiyorovna', HR_B_KADR, 'Yurist', '2024-02-12', '1993-10-09', 9600000, ['ofis' => true, 'ip' => '1014', 'intizom' => 0, 'kelish' => -12]],

        // ---------------- Buxgalteriya
        ['Karimova', 'Gulnora', 'Shavkatovna', HR_B_BUX, 'Bosh buxgalter', '2015-06-15', '1978-01-26', 15500000, ['ofis' => true, 'ip' => '1020', 'intizom' => 0, 'kelish' => -20]],
        ['Ismoilova', 'Feruza', 'Nodirovna', HR_B_BUX, 'Buxgalter (ish haqi)', '2019-10-07', '1989-05-11', 8200000, ['ofis' => true, 'ip' => '1021', 'intizom' => 0, 'kelish' => -12]],
        ['Hasanov', 'Jasur', 'Abdullayevich', HR_B_BUX, 'Buxgalter (moddiy hisob)', '2021-09-20', '1990-08-28', 8000000, ['ofis' => true, 'ip' => '1022', 'intizom' => 1, 'kelish' => -9]],
        ['Olimova', 'Shahnoza', "Dilshod qizi", HR_B_BUX, 'Kassir', '2023-11-13', '1997-04-03', 5800000, ['ofis' => true, 'ip' => '1023', 'intizom' => 0, 'kelish' => -11]],

        // ---------------- Savdo va marketing
        ['Abdullayev', 'Farrux', 'Sherzodovich', HR_B_SAVDO, "Savdo bo'limi boshlig'i", '2017-05-22', '1983-02-19', 16500000, ['ofis' => true, 'ip' => '1030', 'intizom' => 0, 'kelish' => -13]],
        ['Sobirov', 'Umid', "Akmal o'g'li", HR_B_SAVDO, 'Savdo menejeri', '2020-02-03', '1990-06-14', 9200000, ['ofis' => true, 'ip' => '1031', 'intizom' => 1, 'kelish' => -3]],
        ['Rahimova', 'Madina', 'Otabekovna', HR_B_SAVDO, 'Savdo menejeri', '2022-07-18', '1996-11-25', 8800000, ['ofis' => true, 'ip' => '1032', 'intizom' => 0, 'kelish' => -9]],
        ['Mahmudov', 'Timur', "Javlon o'g'li", HR_B_SAVDO, 'Savdo menejeri (viloyatlar)', '2025-03-10', '1998-01-30', 8400000, ['ofis' => true, 'ip' => '1033', 'intizom' => 1, 'kelish' => -2]],
        ['Aminova', 'Sevara', 'Rustamovna', HR_B_SAVDO, 'Marketing mutaxassisi', '2024-09-02', '1999-07-07', 7600000, ['ofis' => true, 'ip' => '1034', 'intizom' => 0, 'kelish' => -7]],
        ['Rahmonov', 'Azamat', "Sardor o'g'li", HR_B_SAVDO, 'Savdo menejeri (stajyor)', ['kun' => 26], '2002-03-12', 5200000, ['ofis' => true, 'ip' => '1035', 'intizom' => 1, 'kelish' => -6]],

        // ---------------- Ishlab chiqarish
        ['Normatov', 'Bobur', 'Karimovich', HR_B_ISH, "Ishlab chiqarish boshlig'i", '2016-01-11', '1981-10-12', 14800000, ['ip' => '1040', 'intizom' => 0, 'kelish' => -28]],
        ['Sultonov', 'Dilshod', 'Ravshanovich', HR_B_ISH, 'Sex ustasi', '2018-09-03', '1985-03-08', 8900000, ['ip' => '1041', 'intizom' => 0, 'kelish' => -32]],
        ['Hamidov', 'Rustam', 'Baxodirovich', HR_B_ISH, 'Sex ustasi', '2019-03-11', '1987-12-01', 8900000, ['ip' => '1042', 'intizom' => 1, 'kelish' => -30]],
        ['Toshpulatov', 'Jamshid', "Nodir o'g'li", HR_B_ISH, 'Ekstruder operatori', '2021-06-14', '1994-05-27', 5600000, ['intizom' => 2, 'kelish' => -26]],
        ['Ibragimov', 'Sherzod', 'Alisherovich', HR_B_ISH, 'Ekstruder operatori', '2022-10-03', '1991-09-15', 5600000, ['intizom' => 1, 'kelish' => -29]],
        ['Valiyev', 'Anvar', "Ismoil o'g'li", HR_B_ISH, "Ta'mirlash mexanigi", '2019-08-19', '1988-02-09', 7300000, ['ip' => '1043', 'intizom' => 0, 'kelish' => -31]],
        ['Jo\'rayev', 'Nodir', 'Mansurovich', HR_B_ISH, "Ta'mirlash mexanigi", '2023-05-22', '1996-12-18', 6900000, ['intizom' => 1, 'kelish' => -27]],
        ['Boboyeva', 'Lola', 'Hakimovna', HR_B_ISH, 'Sifat nazorati inspektori', '2020-11-02', '1992-06-06', 6800000, ['ip' => '1044', 'intizom' => 0, 'kelish' => -18]],
        ['Ahmedov', 'Sardor', "Karim o'g'li", HR_B_ISH, 'Sifat nazorati inspektori', '2024-04-15', '1998-10-22', 6200000, ['intizom' => 0, 'kelish' => -21]],
        ['Raximov', 'Otabek', "Shuhrat o'g'li", HR_B_ISH, 'Elektrik', '2022-02-07', '1990-04-29', 7000000, ['intizom' => 1, 'kelish' => -24]],
        ['Hoshimov', 'Sardor', "Bahrom o'g'li", HR_B_ISH, 'Qadoqlovchi', ['kun' => 12], '2003-08-04', 4200000, ['intizom' => 0, 'kelish' => -30]],

        // ---------------- Ombor va logistika
        ['Egamberdiyev', 'Shavkat', 'Raimovich', HR_B_OMB, 'Ombor mudiri', '2017-10-09', '1982-08-14', 9800000, ['ip' => '1050', 'intizom' => 0, 'kelish' => -22]],
        ['Mamatqulova', 'Nargiza', 'Yoqubovna', HR_B_OMB, 'Omborchi (xomashyo)', '2020-06-01', '1991-01-17', 5900000, ['ip' => '1051', 'intizom' => 0, 'kelish' => -20]],
        ['Xudoyberdiyev', 'Akmal', "Bekzod o'g'li", HR_B_OMB, 'Omborchi (tayyor mahsulot)', '2022-03-21', '1993-11-03', 5900000, ['ip' => '1052', 'intizom' => 1, 'kelish' => -19]],
        ['Qosimov', 'Farhod', 'Anvarovich', HR_B_OMB, 'Haydovchi-ekspeditor', '2019-12-02', '1986-07-30', 6600000, ['intizom' => 1, 'kelish' => -16]],
        ['Davlatov', 'Mansur', "Eldor o'g'li", HR_B_OMB, 'Forklift operatori', '2023-08-28', '1995-09-09', 5400000, ['intizom' => 2, 'kelish' => -24]],
    ];
}
