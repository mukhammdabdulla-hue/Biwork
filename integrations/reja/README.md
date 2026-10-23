# Biwork Rejani (Reja paneli) Biwork mahsuloti sifatida ulash

Biwork Reja (panel kodi: `ngd-panel`) **o'zgarishsiz mustaqil ishlayveradi**. Ulanish — kichik qo'shimcha (patch): 3 ta fayl o'zgaradi, 3 tasi yangi.
`config.php` da `biwork` bo'limi bo'lmasa panel avvalgidek ishlaydi.

| Fayl | Nima |
|---|---|
| `src/Biwork.php` (yangi) | Panel ↔ SDK yelimi: "Ochish" bilan kirish (SSO), litsenziya darvozasi, ogohlantirish |
| `src/BiworkConnect.php` (yangi) | [`sdk/php/BiworkConnect.php`](../../sdk/php/BiworkConnect.php) ning nusxasi (SDK yangilansa — nusxani ham yangilang) |
| `public/license.php` (yangi) | Litsenziya holati va faollashtirish (faqat panel administratori) |
| `src/Auth.php` | `Auth::loginAs()` — parolsiz sessiya ochish (faqat tasdiqlangan token bilan chaqiriladi) |
| `public/index.php` | `?bw_launch` ni qabul qilish; litsenziya darvozasi; imtiyoz davrida ogohlantirish chizig'i |
| `public/api.php` | Litsenziya yo'q bo'lsa API `402 license_required` (`/api/me`, `/api/health`, `/api/logout` bundan mustasno) |

## Qo'llash

```bash
cd ngd-panel
patch -p1 --dry-run < ../biwork/integrations/reja/panel-connector.patch   # avval tekshirish
patch -p1           < ../biwork/integrations/reja/panel-connector.patch
php -l src/Biwork.php && php -l public/license.php
```

Patch panelning hozirgi (v1.8) fayllariga yozilgan. Panel keyin o'zgargan bo'lsa va `patch` qarshilik qilsa — o'zgargan
joylar kichik (yuqoridagi jadval), qo'lda qo'shish oson.

## Sozlash — `config.php` (panel ildizida)

Ikki rejim, ikkalasi ham bitta kod:

```php
<?php return [
    // 1) Brauzer demosi (Biwork serverida): portaldan "Ochish" bilan kiriladi, litsenziya portalda
    'biwork' => [
        'server' => 'https://biwork.uz',   // portal manzili
        'tool'   => 'reja',
        'sso'    => true,
    ],

    // 2) Mijoz kompyuteridagi ilova/server: litsenziyasiz ishlamaydi
    // 'biwork' => ['server' => 'https://biwork.uz', 'tool' => 'reja', 'sso' => false, 'enforce' => true, 'app_version' => '1.8'],
];
```

Ikkala holatda ham portal ochiq kalitlari kerak: `biwork-public.json` (panel ildizida) —

```bash
curl -s https://biwork.uz/api/v1/pubkey > biwork-public.json
```

(Reliz paketini yig'ishda shu fayl paketga qo'shiladi; kalit almashtirilsa portal eskilarini ham saqlaydi.)

- `sso` — portaldan `?bw_launch=` tokeni bilan kirishga ruxsat. Demo rejimda foydalanuvchi panelda **`planner`** roli oladi
  (umumiy namuna: foydalanuvchilarni boshqarish yo'q); `live` rejimda portal administratori — `admin`, qolganlar — `planner`.
  Panelda `bw-<korxona>-<foydalanuvchi>` loginli hisob ochiladi (parolini hech kim bilmaydi — faqat portaldan kiriladi); uni
  panel administratori *Foydalanuvchilar* sahifasida o'chirishi mumkin.
- `enforce` — faol litsenziyasiz panel ochilmaydi, `/license.php` ga yo'naltiradi. Administrator kalitni kiritadi
  (`data/` papkasi yoziladigan bo'lishi kerak: `biwork-license.json`, `biwork-device.id`, `biwork-jti.json`).
- Litsenziya imtiyoz davrida (internet yo'q, tekshiruv muddati o'tgan) panel ishlaydi, tepada "Litsenziyani onlayn tekshirish kerak" chizig'i chiqadi.

## Tekshirish

Portal sinovlari paketi paneldagi o'zgarishlarni ham tekshiradi (patchni nusxaga qo'llab, ikki panel va portalni ko'tarib):

```bash
cd biwork && bash tests/run.sh          # PANEL_SRC=/yo'l/ngd-panel bilan boshqa joydagi panel
```

Natija: portaldan "Ochish" → panel sessiyasi; takror/muddati o'tgan/boshqa vosita/buzilgan token rad etiladi; litsenziyasiz panel
yopiq; faollashtirish; server tomondan to'xtatish → panel bloklanadi; tiklash; qurilmadan olib tashlash.
