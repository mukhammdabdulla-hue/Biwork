# BIWORK’ni serverga oʻrnatish

Bu yoʻriqnoma BIWORK’ni internetga chiqadigan serverga qadamma-qadam oʻrnatishni tushuntiradi.
Natijada xodimlar `https://sizning-domeningiz` orqali kiradi. HTTPS sertifikati avtomatik
olinadi va yangilanadi, ma’lumotlar har kuni zaxiralanadi.

Oʻrnatish taxminan 30–60 daqiqa oladi. Har bir buyruqni serverda `root` yoki `sudo` huquqli
foydalanuvchi nomidan bajaring.

## 1. Nima kerak

| Nima | Tavsiya |
|---|---|
| Server (VPS) | Ubuntu 24.04 yoki 22.04, kamida 2 vCPU, 4 GB RAM, 40 GB disk. 20 dan ortiq faol foydalanuvchi yoki koʻp skan hujjat boʻlsa: 4 vCPU, 8 GB RAM. |
| Domen | Masalan, `biwork.korxona.uz`. Domenning DNS sozlamalarida **A yozuvi** server IP manziliga yoʻnaltirilgan boʻlishi kerak. |
| AI kaliti | OpenAI, Google Gemini yoki Anthropic API kaliti (bittasi yetarli). Kalit boʻlmasa, tizim «demo rejim»da ishlaydi: ma’lumotlarni qoidalar asosida ajratadi va buni ekranda ochiq koʻrsatadi. |
| SSH kirish | Serverga `ssh` orqali kira olish. |

> **Ma’lumotlar qayerda saqlanadi.** Oʻzbekiston fuqarolarining shaxsiy ma’lumotlari (xodimlar
> ismi, telefoni va h.k.) Oʻzbekiston hududidagi serverlarda saqlanishi qonun bilan talab
> qilinadi. Serverni tanlashdan oldin yuristingiz bilan tekshiring. Tashqi AI provayderiga qaysi
> ma’lumotlar yuborilishi [ai-providers.md](ai-providers.md) va [ai-engine.md](ai-engine.md) da
> yozilgan. Kerak boʻlsa, tashqi AI’ni kompaniya sozlamalarida oʻchirib qoʻyish mumkin.

## 2. Serverni tayyorlash

Serverga kiring va tizimni yangilang:

```bash
ssh root@SERVER_IP
apt update && apt upgrade -y
```

Docker’ni oʻrnating (rasmiy skript):

```bash
curl -fsSL https://get.docker.com | sh
docker compose version        # "Docker Compose version v2..." chiqishi kerak
```

Brandmauerni yoqing: faqat SSH, HTTP va HTTPS ochiq boʻlsin.

```bash
ufw allow OpenSSH
ufw allow 80/tcp
ufw allow 443/tcp
ufw allow 443/udp
ufw --force enable
```

## 3. Dasturni yuklab olish

```bash
git clone https://github.com/mukhammdabdulla-hue/Biwork.git /opt/biwork
cd /opt/biwork
```

## 4. Sozlamalar fayli (`.env`)

Namunadan nusxa oling:

```bash
cp .env.example .env
```

Maxfiy kalitlarni yarating. Har bir buyruq yangi tasodifiy qiymat chiqaradi, ularni yozib oling:

```bash
openssl rand -hex 32                        # JWT_SIGNING_KEY uchun
openssl rand -base64 32 | tr '+/' '-_'      # FIELD_ENCRYPTION_KEY uchun
openssl rand -hex 24                        # POSTGRES_PASSWORD uchun
openssl rand -hex 24                        # APP_DB_PASSWORD uchun
```

`.env` faylini oching (`nano .env`) va quyidagi qatorlarni oʻzgartiring. `biwork.korxona.uz`
oʻrniga oʻz domeningizni yozing:

```ini
APP_ENV=prod
COMPOSE_FILE=docker-compose.yml:docker-compose.prod.yml
BIWORK_DOMAIN=biwork.korxona.uz
CORS_ORIGINS=["https://biwork.korxona.uz"]
COOKIE_SECURE=true

POSTGRES_PASSWORD=<openssl rand -hex 24 natijasi>
APP_DB_PASSWORD=<openssl rand -hex 24 natijasi>
JWT_SIGNING_KEY=<openssl rand -hex 32 natijasi>
FIELD_ENCRYPTION_KEY=<openssl rand -base64 32 | tr '+/' '-_' natijasi>
```

AI sozlamasi. Quyidagi ikki variantdan birini tanlang:

```ini
# 1-variant: haqiqiy AI (kalitlardan kamida bittasi)
AI_PROVIDER=auto
OPENAI_API_KEY=sk-...
# GEMINI_API_KEY=...
# ANTHROPIC_API_KEY=...

# 2-variant: AI kalitisiz, demo rejim (ekranda "Mock AI" deb koʻrsatiladi)
# AI_PROVIDER=demo
```

Modellarni tanlash va narxlar haqida: [ai-engine.md](ai-engine.md).

Faylni saqlang (`Ctrl+O`, `Enter`, `Ctrl+X`) va uni boshqalar oʻqiy olmaydigan qiling:

```bash
chmod 600 .env
```

> `APP_ENV=prod` da dastur xavfsiz boʻlmagan sozlamalar bilan ishga tushmaydi: namunadagi
> «change-me» qiymatlari, boʻsh shifrlash kaliti yoki HTTPS’siz cookie. Xato boʻlsa, u
> `docker compose logs api` da aniq yoziladi.

## 5. Ishga tushirish

```bash
docker compose up -d --build
```

Birinchi yigʻish 5–15 daqiqa oladi. Keyin holatni tekshiring. Barcha xizmatlar `running` yoki
`healthy`, `migrate` esa `exited (0)` boʻlishi kerak:

```bash
docker compose ps
```

Birinchi administratorni yarating. Email va parolni oʻzingiznikiga almashtiring; parol kamida
10 belgi, katta va kichik harf hamda raqamdan iborat boʻlishi shart:

```bash
docker compose run --rm migrate python -m app.cli create-admin \
  --email admin@korxona.uz --password 'Kuchli-Parol-2026' --name "Administrator"
```

Brauzerda `https://biwork.korxona.uz` manzilini oching. HTTPS sertifikati birinchi marta
bir daqiqagacha olinadi.

## 6. Dastlabki sozlash (brauzerda)

Til oʻngdagi yuqori burchakda tanlanadi (UZ / RU / EN). Quyidagi nomlar oʻzbekcha interfeysdagi
nomlardir.

1. Yaratilgan administrator bilan kiring.
2. **Kompaniya yarating:** kompaniya nomi, kodi va STIRni kiriting. Platforma administratori
   buni kirgandan keyingi sahifada va «Ma’muriyat → Kompaniyalar» boʻlimida qila oladi.
3. **Boʻlimlar (sexlar):** «Ma’muriyat → Boʻlimlar». Hujjatlarda uchraydigan boshqa nomlarni
   «Muqobil nomlar» maydoniga yozing (masalan, «1-sex», «Цех №1»). BIWORK Agent ularni taniydi.
4. **Mahsulotlar, smenalar va liniyalar:** «Ishlab chiqarish → Mahsulotlar» va
   «Ishlab chiqarish → Ma’lumotnomalar»; **omborlar:** «Zaxiralar → Omborlar».
5. **«Sozlamalar → Kompaniya»:** «Tayyor mahsulot ombori»ni tanlang. Aks holda tasdiqlangan
   ishlab chiqarish hisobotlari ombor qoldigʻiga yozilmaydi.
6. **«Sozlamalar → AI va ma’lumotlar bilan ishlash»:** tashqi AI’ga ruxsat, shaxsiy
   ma’lumotlarni yashirish (telefon, pasport, karta raqamlari) va AI soʻrovlarini saqlash
   muddatini belgilang.
7. **Foydalanuvchilar:** «Ma’muriyat → Foydalanuvchilar». Har bir xodimga rol bering
   (administrator, menejer, operator, kuzatuvchi) va kerak boʻlsa boʻlimlar bilan cheklang.
8. Administratorlar uchun **ikki bosqichli autentifikatsiya (MFA)** ni yoqing:
   «Sozlamalar → Profil va xavfsizlik».

## 7. Zaxira nusxa (backup)

Ma’lumotlar bazasi va yuklangan fayllarni har kuni soat 02:15 da zaxiralash:

```bash
mkdir -p /opt/biwork/backups
crontab -e
```

Ochilgan faylning oxiriga qoʻshing:

```cron
15 2 * * * cd /opt/biwork && ./deploy/backup.sh >> backups/backup.log 2>&1
```

Zaxiralar `/opt/biwork/backups` da 14 kun saqlanadi (`BACKUP_KEEP_DAYS` bilan oʻzgartirish
mumkin). **Zaxiralarni boshqa joyga ham nusxalang**, masalan, boshqa serverga yoki kompyuterga:

```bash
rsync -a root@SERVER_IP:/opt/biwork/backups/ ./biwork-backups/
```

Zaxiradan tiklash. Joriy ma’lumotlar **almashtiriladi**, skript tasdiqlashni soʻraydi:

```bash
cd /opt/biwork
./deploy/restore.sh backups/biwork-db-20261022-021500.dump backups/biwork-files-20261022-021500.tar.gz
```

Tiklashni oldindan, sinov serverida bir marta tekshirib koʻring.

## 8. Yangilash

Yangi versiyani oʻrnatishdan oldin zaxira oling:

```bash
cd /opt/biwork
./deploy/backup.sh
git pull
docker compose up -d --build
```

Ma’lumotlar bazasi migratsiyalari `migrate` xizmati orqali avtomatik bajariladi.

## 9. Kuzatish va muammolarni hal qilish

| Belgi | Nima qilish kerak |
|---|---|
| Xizmatlar holati | `docker compose ps` |
| Jurnallar | `docker compose logs -f api worker` (chiqish: `Ctrl+C`) |
| Tizim holati | `curl https://biwork.korxona.uz/api/v1/health/ready`. Dasturda: «Ma’muriyat → Tizim holati» |
| Sayt ochilmayapti, sertifikat xatosi | Domenning A yozuvi server IP’siga yoʻnalganini va 80/443 portlar ochiqligini tekshiring; `docker compose logs caddy` |
| `api` qayta-qayta oʻchib yonmoqda | `docker compose logs api` da «Unsafe configuration» boʻlsa, `.env` dagi koʻrsatilgan qiymatni tuzating va `docker compose up -d` |
| 502 Bad Gateway | API hali ishga tushmagan: `docker compose ps`, keyin `docker compose logs api` |
| AI xatolari | Kalit va limitlarni tekshiring. AI sarfi va xatolari «Tahlil → BIWORK Agent» va «Ma’muriyat → Tizim holati» da koʻrinadi |
| Diskda joy qolmadi | `df -h`; eski zaxiralarni oʻchiring; `docker system prune` (ishlatilmayotgan obrazlarni oʻchiradi) |

## 10. Xavfsizlik eslatmalari

- `.env` faylini hech qachon GitHub’ga yoki chatga yubormang. Unda barcha maxfiy kalitlar bor.
- Serverga SSH kalit bilan kiring va parol bilan kirishni oʻchiring (`/etc/ssh/sshd_config`:
  `PasswordAuthentication no`).
- Oyiga bir marta `apt update && apt upgrade -y` qiling va dasturni yangilang (8-boʻlim).
- Barcha administratorlar uchun MFA’ni yoqing va har bir xodimga faqat kerakli rolni bering.
- Zaxira nusxalarni boshqa joyda ham saqlang va tiklashni vaqti-vaqti bilan tekshiring.
- Audit jurnali oʻzgartirib boʻlmaydigan qilib saqlanadi. Uning butunligini «Ma’muriyat →
  Audit jurnali → Yaxlitlikni tekshirish» tugmasi bilan tekshirish mumkin.
