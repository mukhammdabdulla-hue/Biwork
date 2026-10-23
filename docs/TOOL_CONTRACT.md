# Vosita shartnomasi (Biwork Tool Contract v1)

Biwork'dagi har bir mahsulot ("vosita" — uning texnik ulanishi: Biwork Reja, Biwork HR, …) platformaga **bir xil to'rt qoida bilan** ulanadi.
Shartnomaga amal qilgan vosita katalogda ko'rinadi, brauzerda ochiladi, yuklab olinadi va litsenziya bilan boshqariladi —
portalga tegmasdan.

| # | Qoida | Hajmi |
|---|---|---|
| 1 | **Manifest** — vosita o'zi haqida `manifest.json` beradi (katalog, tarif, sinov) | 1 fayl |
| 2 | **Reliz paketi** — yuklab olinadigan arxiv + versiya, sha256 | `zip` |
| 3 | **Litsenziya tekshiruvi** — ilova rejimida (o'rnatilgan nusxa) kalit bilan faollashadi, imzolangan tokenni tekshiradi | SDK |
| 4 | **"Ochish" tokeni** — portaldan brauzer orqali kirganda sessiya ochadi (parolsiz) | SDK |

3 va 4-qoida uchun tayyor kutubxonalar bor: [`sdk/php/BiworkConnect.php`](../sdk/php/BiworkConnect.php),
[`sdk/python/biwork_license.py`](../sdk/python/biwork_license.py) (bir xil mantiq, bir xil fayl formatlari). Boshqa tilda
(Node, Go, .NET) yozilsa — quyidagi protokol yetarli: Ed25519 imzosini tekshirish va 4 ta HTTP so'rov.

---

## 1. Manifest (`manifest.json`)

Portalga `php tools/cli.php tool:register manifest.json` bilan (yoki admin panelida) yuklanadi. Qayta yuklansa — vosita
yangilanadi, **mavjud tariflar (narx, sozlamalar) o'zgarmaydi**, lekin matnlar, `marketing`, `web.url` va `status` manifestdagilar bilan almashadi
(admin formasida yozilganlar ustiga yoziladi: admin panelida mavjud mahsulotni almashtirish alohida belgi bilan tasdiqlanadi).
Manifestsiz yo'l ham bor: admin panelidagi mahsulot formasi (*Mahsulotlar → Yangi mahsulot*), [SITE_VA_REKLAMA.md](SITE_VA_REKLAMA.md). Namuna: [`integrations/reja/manifest.json`](../integrations/reja/manifest.json).

| Maydon | Majburiy | Ma'nosi |
|---|---|---|
| `contract` | ha | Shartnoma versiyasi (hozir `1`) |
| `slug` | ha | Ochiq saytdagi qisqa manzil (`/reja`) va `aud` nomi: kichik lotin harf, raqam, `-`. Saytning boshqa sahifalari nomi (`admin`, `app`, `api`, `login`, `mahsulotlar`, `sitemap`, … — `Catalog::RESERVED_SLUGS`) band: rad etiladi |
| `code` | ha | 3 ta bosh harf/raqam; litsenziya kalitida qatnashadi (`BW-REJ-…`). Keyin o'zgarmaydi |
| `name`, `tagline`, `description`, `highlights[]`, `category`, `icon` | `name` ha | Katalog matni (o'zbekcha). `name` — `Biwork <Nom>` shaklida ("Biwork Reja"); `icon` — `Ui::ICONS` dagi nom |
| `status` | yo'q | `active` · `soon` ("tez orada", ochilmaydi) · `hidden` |
| `web.url` | yo'q | Brauzer demosi manzili. Bo'sh bo'lsa "Brauzerda ochish" ko'rinmaydi |
| `trial.enabled`, `trial.days` | yo'q | Sinov: bir korxonaga bir marta, **faqat brauzerdagi demo** (ilova faollashmaydi, yuklab bo'lmaydi) |
| `plans[]` | yo'q | `code`, `name`, `type` (`paid`), `seats` (bir vaqtda faol qurilmalar), `days` (bo'sh = muddatsiz), `limits{}`, `features[]`, `price` (so'm, bo'sh = "so'rov bo'yicha") |
| `package` | yo'q | Talablar (`min_php`, `extensions`) — mijozga ko'rsatiladi |
| `marketing` | yo'q | Ochiq saytdagi **reklama sahifasi** matnlari va rasmlari — §1.1 |

### 1.1 Reklama bo'limi (`marketing`)

Mahsulotning ochiq saytdagi sahifasi (`/<slug>`) shu bo'limdan chiziladi. Hammasi ixtiyoriy: berilmagan bo'lim sahifada ko'rinmaydi
(minimum — `name`, `tagline`, `description`). Matn tozalanadi (uzunlik chegarasi, begona kalitlar tashlanadi); sahifada HTML ekranlanadi.

```json
"marketing": {
  "headline": "Sahifaning bosh sarlavhasi (≤160)",
  "lead": "Sarlavha ostidagi qisqa tushuntirish (≤700); meta tavsif uchun ham zaxira",
  "badges": ["O'zbek tilida", "Excel'ga eksport"],                                   // ≤6, har biri ≤50
  "problems": [{"title": "Muammo", "text": "…"}],                                    // ≤6  "Qaysi muammolarni hal qiladi"
  "features": [{"title": "Imkoniyat", "text": "…", "points": ["…"], "shot": "gantt.webp"}],  // ≤8  "Nimalar qila oladi"; shot — ekran rasmi
  "audience": [{"title": "Kimga", "text": "…"}],                                     // ≤6  "Kimga kerak"
  "shots": [{"file": "gantt.webp", "caption": "Rasm ostidagi yozuv", "alt": "Rasm tavsifi"}],  // ≤12; birinchisi — bosh rasm
  "faq": [{"q": "Savol", "a": "Javob"}],                                             // ≤10  (qidiruvda savol-javob sifatida chiqishi mumkin)
  "seo": {"title": "≤70 belgi", "description": "≤180 belgi", "keywords": ["…"]},
  "cta": {"trial": "Sinab ko'rish", "lead": "So'rov qoldirish", "lead_title": "…", "lead_text": "…", "placeholder": "Murojaat shakli izoh maydonidagi namuna savol (≤140)"}
}
```

- **Rasmlar** `portal/public/assets/products/<slug>/` papkasida turadi. Fayl nomi kichik lotin harf/raqam/`.`/`_`/`-`, kengaytma `.webp|.png|.jpg`;
  diskda bo'lmagan rasm sahifadan tushib qoladi. Tayyorlash: `python3 tools/product_assets.py <slug> --shot gantt=ekran.png … --og-title "Biwork Reja" --og-tagline "…" --og-shot gantt`
  (rasmlarni 1600 px/webp ga o'tkazadi, kerak bo'lsa `--mask x,y,w,h` bilan maxfiy yozuvni yopadi, `og.png` — 1200×630 ulashish rasmini yasaydi).
- **Halol da'vo qoidasi**: raqam va va'dalar faqat tekshirilgan bo'lsin (masalan namuna ma'lumotdagi natija "namuna ma'lumotda" deb yozilsin).
  Ekran rasmlarida haqiqiy mijoz nomi, narx, shaxsiy ma'lumot ko'rinmasin.
- **"Sinab ko'rish"** tugmasi, "30 kun bepul sinov" yozuvi va "Brauzerda sinab ko'ring" qadami faqat mahsulotning **brauzer manzili** (`web.url` yoki admin → Mahsulotlar → *Brauzer (demo) manzili*)
  berilgan bo'lsa chiqadi; bo'sh bo'lsa sahifada faqat "So'rov qoldirish" qoladi (ishlamaydigan sinov va'da qilinmaydi).
- `status: soon` bo'lsa sahifa "Tez orada" bo'ladi va "Sinab ko'rish" o'rniga kutish ro'yxati (murojaat shakli) chiqadi; `hidden` — sahifa ham, sitemap ham yo'q.
- To'liq yo'riqnoma (qidiruv, ijtimoiy tarmoq, reklama havolalari, murojaatlar): [SITE_VA_REKLAMA.md](SITE_VA_REKLAMA.md).

`features` — vosita o'zi tanlaydigan kalitlar; platforma uchun standart: `web_demo`, `app`, `download`.
`limits` — vosita o'zi sharhlaydi (masalan `{"users": 5}`); token ichida keladi.

## 2. Reliz paketi

- Admin → *Vositalar* → *Reliz qo'shish* (yoki `php tools/cli.php release:add reja 1.8 server paket.zip "izoh"`).
- `kind`: `server` (hosting/server uchun zip) · `windows` (o'rnatuvchi, 2-bosqich) · `source`.
- Portal `sha256` va hajmni o'zi hisoblaydi; yuklash **faqat faol (to'langan yoki ichki) litsenziyali mijozga**, har yuklash jurnalga yoziladi.
- Paket ichida `biwork-public.json` (portal `/api/v1/pubkey` javobi) va ulangan SDK bo'lishi kerak; `config.php` dagi `biwork`
  bo'limi portal manzilini beradi.

## 3. Litsenziya protokoli (ilova rejimi)

### 3.1 Qiymatlar

- **Kalit**: `BW-<KOD>-XXXX-XXXX-XXXX` (alifbo `0/O/1/I` siz). Kiritishda katta-kichik harf va tire/bo'shliq farqi yo'q.
- **Qurilma izi** (`device_id`): vosita o'rnatilganda 16 bayt tasodifiy son yaratib `data/biwork-device.id` ga yozadi; so'rovda
  `sha256(o'rnatish_id | host | machine-id)` ning 32 hex belgisi yuboriladi. Papka boshqa kompyuterga ko'chirilsa iz o'zgaradi —
  litsenziya "boshqa qurilmaga bog'langan" deb yaroqsiz bo'ladi va qayta faollashtiriladi (o'rindiq qo'shimcha sarflanmaydi,
  agar eski qurilma bo'shatilgan bo'lsa).
- **Token** `bwk1.<payload b64url>.<imzo b64url>`; imzo = Ed25519 `"bwk1." + <payload b64url>` ustidan (tur prefiksi tokenlarni
  bir-biriga almashtirib bo'lmasligini ta'minlaydi). Ochiq kalitlar: `GET /api/v1/pubkey` → `{"keys":[{"kid","alg":"Ed25519","public":b64url}]}`.
  `kid` kalit almashtirishda ishlaydi: eski ochiq kalitlar ro'yxatda qoladi.

### 3.2 API (`/api/v1`, JSON)

Barcha javoblar: muvaffaqiyat `{"ok":true,"server_time":<unix>,...}`, xato `{"ok":false,"error":{"code","message"}}` + HTTP holati.
`message` — foydalanuvchiga ko'rsatsa bo'ladigan o'zbekcha matn.

| So'rov | Tana | Muvaffaqiyat javobi |
|---|---|---|
| `GET /health` | — | `{ok, service, api, time}` |
| `GET /pubkey` | — | `{ok, issuer, keys[]}` |
| `POST /licenses/activate` | `key`, `device_id`, `tool`, `device_name?`, `platform?`, `app_version?` | `{token, license{key,tool,customer,type,plan,seats,seats_used,valid_until,days_left}}` |
| `POST /licenses/check` | `key`, `device_id`, `tool?`, `app_version?` | xuddi shu (yangi token) |
| `POST /licenses/deactivate` | `key`, `device_id` | `{ok:true}` — o'rindiq bo'shaydi |

Xato kodlari:

| `error.code` | HTTP | Ma'nosi | Vosita nima qiladi |
|---|---|---|---|
| `invalid_key` | 404 | Kalit topilmadi | Xabarni ko'rsatadi; `check` da — bloklaydi |
| `tool_mismatch` | 403 | Kalit boshqa vosita uchun | bloklaydi |
| `suspended` | 403 | Vaqtincha to'xtatilgan | **darhol bloklaydi**, ~15 daqiqada bir qayta so'raydi (tiklanishi mumkin) |
| `revoked` | 403 | Bekor qilingan | **darhol bloklaydi**, faqat qayta faollashtirish bilan ochiladi |
| `expired` | 403 | Muddati tugagan | bloklaydi, har 15 daqiqada qayta so'raydi (uzaytirilishi mumkin) |
| `not_started` | 403 | Hali boshlanmagan | bloklaydi |
| `not_activated` | 403 | Qurilma faollashtirilmagan yoki o'rindiq bo'shatilgan | bloklaydi |
| `seats_full` | 409 | O'rindiqlar band; `error.seats`, `error.devices[]` | Qaysi qurilmalar bandligini ko'rsatadi |
| `trial_no_app` | 403 | Sinov litsenziyasi ilovani faollashtirmaydi | Pullik litsenziya kerakligini aytadi |
| `bad_request` | 400/413 | So'rov noto'g'ri | — |
| `rate_limited` | 429 | Juda ko'p so'rov / noto'g'ri kalit (daqiqada 120 so'rov, 10 daqiqada 10 noto'g'ri kalit — IP bo'yicha) | Keyinroq qayta uriniladi |
| tarmoq xatosi / 5xx | — | Serverga yetib bo'lmadi | **bloklamaydi** — imtiyoz muddati ichida ishlayveradi |

### 3.3 Token ichidagi maydonlar (`payload`)

| Maydon | Ma'nosi |
|---|---|
| `v`, `iss`, `jti`, `kid` | Versiya (1), "biwork", token id, imzolagan kalit |
| `lic`, `lid` | Litsenziya kaliti va id'si |
| `tool`, `tool_code` | Vosita slug va kodi — vosita **o'zinikini** tekshiradi |
| `cust{id,name}`, `type`, `plan`, `features[]`, `limits{}`, `seats` | Korxona va tarif |
| `dev` | Bog'langan qurilma izi — vosita o'zinikiga tenglashtiradi |
| `iat`, `nbf`, `exp` | Berilgan vaqt, boshlanish, tugash (`null` = muddatsiz) |
| `chk` | **Keyingi onlayn tekshiruv muddati** (odatda berilganidan +14 kun, `exp` dan oshmaydi) |
| `grace` | `chk` dan keyin internetsiz ishlash imtiyozi, soniyada (odatda 7 kun) |

### 3.4 Vosita tekshiruv algoritmi (oflayn)

Har ishga tushishda/so'rovda (`ensure()`):

```
token yo'q                         → none      (faollashtirish sahifasi)
imzo yaroqsiz / boshqa tool / dev≠o'zimniki → invalid  (kalitni qayta kiritish)
server avval rad etgan (blocked)   → blocked   (xabar; suspended/expired bo'lsa 15 daqiqada bir qayta so'raladi)
hozir + 1 kun < ko'rilgan eng katta vaqt (max_seen) → clock    (soat orqaga surilgan)
nbf > hozir + 1 kun                → invalid   (hali boshlanmagan)
exp bor va hozir > exp             → expired   (bloklaydi)
hozir > chk + grace                → locked    (bloklaydi: "internetga ulanib tekshiring")
hozir > chk                        → grace     (ISHLAYDI + ogohlantirish; onlayn yangilashga urinadi)
aks holda                          → valid     (ishlaydi; chk ga 1 kun qolsa yangilashga urinadi)
```

- Yangilash urinishlari orasida kamida 15 daqiqa kutiladi (offlayn kompyuter har so'rovda serverni urmasligi uchun).
- Serverdan kelgan har token **imzosi tekshirilib**, `dev` mosligi tasdiqlangach saqlanadi; imzosiz javob saqlanmaydi.
- `max_seen` har tekshiruvda yangilanadi — soatni orqaga surib muddatni cho'zishga qarshi.
- Vosita foydalanuvchiga **holat sahifasini** berishi kerak (Reja panelida `/license.php`): holat, korxona, tarif, muddat,
  qurilma izi, "Faollashtirish", "Hozir tekshirish", "Qurilmadan olib tashlash". `grace` holatda esa ishchi oynada ogohlantirish.
- Litsenziyani faqat vosita administratori boshqaradi.

**Cheklov (ochiq aytamiz).** Manba kodi ko'rinadigan vositada (PHP) bu tekshiruv to'siq va nazorat, DRM emas. Bekor qilish
oflayn vositaga **keyingi tekshiruvda** ta'sir qiladi (eng ko'pi bilan `chk` oynasi, odatda ≤14 kun, `suspended/revoked` da — onlayn
bo'lgan zahoti).

## 4. "Ochish" tokeni (brauzer orqali kirish)

Mijoz portalda *Brauzerda ochish* ni bosadi → portal litsenziyani tekshiradi → **2 daqiqalik, bir martalik** imzolangan token bilan
vositaning veb manziliga yo'naltiradi: `https://<vosita>/?bw_launch=bwl1.<payload>.<imzo>`.
Litsenziya kaliti tokenga **kirmaydi**; portal `Referrer-Policy: no-referrer` yuboradi.

Payload (`bwl1`): `v`, `iss`, `aud` (= vosita `slug`), `jti`, `iat`, `exp` (= `iat`+120), `mode`, `user{id,name,email,role,tool_role}`,
`cust{id,name}`, `lic{id,type,plan,features[],limits{},exp}`, `portal` (qaytish havolasi), `kid`.

**Vosita ketma-ketligi:**

1. `?bw_launch` bor → boshqa hech narsadan oldin tekshir: tur `bwl1`, imzo (ochiq kalit), `aud` = o'zim, `exp` o'tmagan,
   `iat` ≤ hozir+120 s.
2. `jti` bir marta: ko'rilgan `jti` lar kichik keshda (`exp`+60 s gacha) saqlanadi; takror → rad (`replay`). Kesh fayli qulf bilan yoziladi.
3. Foydalanuvchini topadi/yaratadi: `bw-<cust.id>-<user.id>` kabi barqaror login, **tasodifiy noma'lum parol** (faqat portal orqali kiriladi),
   ism — `user.name`. Vositada o'chirilgan foydalanuvchi kirmaydi.
4. Rol: `mode=demo` → **cheklangan** rol (sozlash/foydalanuvchi boshqaruvi yo'q); `mode=live` va `tool_role=admin` → vosita administratori,
   aks holda oddiy foydalanuvchi. (`mode=live` — pullik litsenziyada **shaxsiy nusxa manzili** (`instance_url`) berilganda; manzil bo'lmasa yoki sinov litsenziyasida — `demo`.)
5. O'z sessiyasini ochadi, so'ng **`303` bilan tokensiz manzilga** yo'naltiradi (token manzil qatorida/tarixda qolmasin).
   Rad etilsa — sessiyasiz, tushunarli xabar + portalga qaytish havolasi (403).

Demo oqimda vosita **umumiy namunaviy ma'lumot** bilan ochilishi kerak; mijozning haqiqiy ma'lumoti umumiy serverga yuklanmaydi.

## 5. Brend va ko'rinish

- Logotip: Biwork (yashil + to'q ko'k). Ranglar: ko'k `#041C31`, yashil `#0A9F73` (qorong'i mavzuda `#2FD39B`). Shrift — Inter.
- Til — o'zbek (lotin). Vosita sahifasida "Biwork portaliga qaytish" havolasi (`payload.portal`).
- Mahsulot nomi `Biwork <Nom>` shaklida (masalan "Biwork Reja"); mijoz korxonasi nomi alohida ko'rsatiladi.

## 6. Yangi vosita qo'shish — ro'yxat

1. Mahsulotni admin formasida (*Mahsulotlar → Yangi mahsulot*) yoki `manifest.json` (`slug`, `code`, tariflar, **`marketing`** — reklama sahifasi) + `tool:register` bilan qo'shing — katalogda va `/<slug>` sahifasida paydo bo'ladi (`status: soon` bilan boshlab turish mumkin). Ekran rasmlari: `tools/product_assets.py` (§1.1).
2. Vositaga SDK ni ulang: `BiworkConnect` (PHP) yoki `biwork_license` (Python). `config` da `server` (portal), `tool` (slug).
3. `?bw_launch` ni qabul qiling (§4) va, agar ilova sifatida yetkazilsa, litsenziya darvozasi + holat sahifasini qo'ying (§3.4).
4. Paketlang (`biwork-public.json` bilan) → `release:add`.
5. Sinov: portaldan *Ochish* → vosita; ilova: kalit → faollashtirish → to'xtatish → bloklanish → tiklash. Namuna sinovlar:
   `tests/panel_connector.php`, `tests/python_sdk_test.py`.

Namuna ulanish — Biwork Reja: [`integrations/reja/README.md`](../integrations/reja/README.md).

## 7. Moslik va o'zgarishlar

- `contract: 1`. Token formatlari (`bwk1`, `bwl1`) va API (`/api/v1`) versiyalangan; mos kelmaydigan o'zgarish yangi prefiks/versiya bilan chiqadi,
  eskisi ishlashda davom etadi.
- Imzolash kalitini almashtirish (`php tools/cli.php keys:rotate`): yangi tokenlar yangi kalit bilan, eski tokenlar eski ochiq kalit bilan
  tekshiriladi — vositalar `/api/v1/pubkey` dan yangi ro'yxatni oladi yoki paketga qayta joylanadi.
