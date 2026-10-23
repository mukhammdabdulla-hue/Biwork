<?php
declare(strict_types=1);

/** Baza sxemasi. Vaqtlar — unix sekund (UTC). Migratsiyalar PRAGMA user_version bilan ketma-ket. */
final class Schema
{
    private const MIGRATIONS = [
        1 => <<<'SQL'
CREATE TABLE customers (
  id INTEGER PRIMARY KEY,
  name TEXT NOT NULL,
  stir TEXT NOT NULL DEFAULT '',
  city TEXT NOT NULL DEFAULT '',
  phone TEXT NOT NULL DEFAULT '',
  email TEXT NOT NULL DEFAULT '',
  notes TEXT NOT NULL DEFAULT '',
  status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active','blocked')),
  source TEXT NOT NULL DEFAULT 'staff',
  created_at INTEGER NOT NULL
);
CREATE TABLE users (
  id INTEGER PRIMARY KEY,
  customer_id INTEGER REFERENCES customers(id) ON DELETE CASCADE,
  email TEXT NOT NULL UNIQUE COLLATE NOCASE,
  name TEXT NOT NULL DEFAULT '',
  phone TEXT NOT NULL DEFAULT '',
  password_hash TEXT NOT NULL,
  role TEXT NOT NULL CHECK (role IN ('staff','owner','member')),
  active INTEGER NOT NULL DEFAULT 1,
  must_change_password INTEGER NOT NULL DEFAULT 0,
  last_login_at INTEGER,
  created_at INTEGER NOT NULL
);
CREATE INDEX idx_users_customer ON users(customer_id);
CREATE TABLE tools (
  id INTEGER PRIMARY KEY,
  slug TEXT NOT NULL UNIQUE,
  code TEXT NOT NULL UNIQUE,
  name TEXT NOT NULL,
  tagline TEXT NOT NULL DEFAULT '',
  description TEXT NOT NULL DEFAULT '',
  category TEXT NOT NULL DEFAULT '',
  icon TEXT NOT NULL DEFAULT 'tool',
  status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active','soon','hidden')),
  web_url TEXT NOT NULL DEFAULT '',
  trial_days INTEGER NOT NULL DEFAULT 14,
  trial_enabled INTEGER NOT NULL DEFAULT 1,
  highlights TEXT NOT NULL DEFAULT '[]',
  sort INTEGER NOT NULL DEFAULT 100,
  created_at INTEGER NOT NULL
);
CREATE TABLE plans (
  id INTEGER PRIMARY KEY,
  tool_id INTEGER NOT NULL REFERENCES tools(id) ON DELETE CASCADE,
  code TEXT NOT NULL,
  name TEXT NOT NULL,
  type TEXT NOT NULL DEFAULT 'paid' CHECK (type IN ('trial','paid')),
  seats INTEGER NOT NULL DEFAULT 1,
  days INTEGER,
  price INTEGER,
  currency TEXT NOT NULL DEFAULT 'UZS',
  limits TEXT NOT NULL DEFAULT '{}',
  features TEXT NOT NULL DEFAULT '[]',
  active INTEGER NOT NULL DEFAULT 1,
  sort INTEGER NOT NULL DEFAULT 100,
  UNIQUE (tool_id, code)
);
CREATE TABLE tool_releases (
  id INTEGER PRIMARY KEY,
  tool_id INTEGER NOT NULL REFERENCES tools(id) ON DELETE CASCADE,
  version TEXT NOT NULL,
  kind TEXT NOT NULL DEFAULT 'server' CHECK (kind IN ('server','windows','source')),
  filename TEXT NOT NULL,
  path TEXT NOT NULL,
  size INTEGER NOT NULL DEFAULT 0,
  sha256 TEXT NOT NULL DEFAULT '',
  notes TEXT NOT NULL DEFAULT '',
  is_latest INTEGER NOT NULL DEFAULT 0,
  active INTEGER NOT NULL DEFAULT 1,
  published_at INTEGER NOT NULL,
  UNIQUE (tool_id, version, kind)
);
CREATE TABLE licenses (
  id INTEGER PRIMARY KEY,
  license_key TEXT NOT NULL UNIQUE,
  customer_id INTEGER NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
  tool_id INTEGER NOT NULL REFERENCES tools(id),
  plan_code TEXT NOT NULL DEFAULT '',
  type TEXT NOT NULL CHECK (type IN ('trial','paid','internal')),
  status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active','suspended','revoked')),
  seats INTEGER NOT NULL DEFAULT 1,
  limits TEXT NOT NULL DEFAULT '{}',
  features TEXT NOT NULL DEFAULT '[]',
  valid_from INTEGER NOT NULL,
  valid_until INTEGER,
  check_days INTEGER NOT NULL DEFAULT 14,
  grace_days INTEGER NOT NULL DEFAULT 7,
  instance_url TEXT NOT NULL DEFAULT '',
  note TEXT NOT NULL DEFAULT '',
  created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
  created_at INTEGER NOT NULL,
  updated_at INTEGER NOT NULL
);
CREATE INDEX idx_lic_customer ON licenses(customer_id);
CREATE INDEX idx_lic_tool ON licenses(tool_id);
CREATE TABLE activations (
  id INTEGER PRIMARY KEY,
  license_id INTEGER NOT NULL REFERENCES licenses(id) ON DELETE CASCADE,
  device_id TEXT NOT NULL,
  device_name TEXT NOT NULL DEFAULT '',
  platform TEXT NOT NULL DEFAULT '',
  app_version TEXT NOT NULL DEFAULT '',
  status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active','released')),
  first_seen INTEGER NOT NULL,
  last_seen INTEGER NOT NULL,
  last_ip TEXT NOT NULL DEFAULT '',
  released_at INTEGER,
  released_by TEXT NOT NULL DEFAULT '',
  UNIQUE (license_id, device_id)
);
CREATE TABLE license_events (
  id INTEGER PRIMARY KEY,
  ts INTEGER NOT NULL,
  license_id INTEGER REFERENCES licenses(id) ON DELETE SET NULL,
  customer_id INTEGER REFERENCES customers(id) ON DELETE SET NULL,
  actor TEXT NOT NULL DEFAULT '',
  action TEXT NOT NULL,
  detail TEXT NOT NULL DEFAULT '',
  ip TEXT NOT NULL DEFAULT ''
);
CREATE INDEX idx_ev_license ON license_events(license_id, ts);
CREATE INDEX idx_ev_ts ON license_events(ts);
CREATE TABLE purchase_requests (
  id INTEGER PRIMARY KEY,
  customer_id INTEGER NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
  user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
  tool_id INTEGER NOT NULL REFERENCES tools(id),
  plan_code TEXT NOT NULL DEFAULT '',
  seats INTEGER NOT NULL DEFAULT 1,
  comment TEXT NOT NULL DEFAULT '',
  status TEXT NOT NULL DEFAULT 'new' CHECK (status IN ('new','handled','rejected')),
  license_id INTEGER REFERENCES licenses(id) ON DELETE SET NULL,
  handled_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
  handled_at INTEGER,
  created_at INTEGER NOT NULL
);
CREATE TABLE rate_limits (
  bucket TEXT PRIMARY KEY,
  window_start INTEGER NOT NULL,
  hits INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE settings (
  k TEXT PRIMARY KEY,
  v TEXT NOT NULL
);
SQL,
        // 2: reklama sahifasi (marketing ma'lumoti) va ochiq saytdan kelgan murojaatlar
        2 => <<<'SQL'
ALTER TABLE tools ADD COLUMN marketing TEXT NOT NULL DEFAULT '{}';
ALTER TABLE tools ADD COLUMN updated_at INTEGER NOT NULL DEFAULT 0;
UPDATE tools SET updated_at = created_at;
CREATE TABLE leads (
  id INTEGER PRIMARY KEY,
  tool_id INTEGER REFERENCES tools(id) ON DELETE SET NULL,
  name TEXT NOT NULL,
  company TEXT NOT NULL DEFAULT '',
  phone TEXT NOT NULL DEFAULT '',
  email TEXT NOT NULL DEFAULT '',
  message TEXT NOT NULL DEFAULT '',
  source TEXT NOT NULL DEFAULT '',
  status TEXT NOT NULL DEFAULT 'new' CHECK (status IN ('new','contacted','won','lost')),
  note TEXT NOT NULL DEFAULT '',
  ip TEXT NOT NULL DEFAULT '',
  created_at INTEGER NOT NULL,
  updated_at INTEGER NOT NULL
);
CREATE INDEX idx_leads_status ON leads(status, created_at);
SQL,
        // 3: admin formasi — tarif matni, mahsulot manzili o'zgarganda eski havola
        3 => <<<'SQL'
ALTER TABLE plans ADD COLUMN price_text TEXT NOT NULL DEFAULT '';
ALTER TABLE plans ADD COLUMN blurb TEXT NOT NULL DEFAULT '';
CREATE TABLE tool_slug_history (
  slug TEXT PRIMARY KEY,
  tool_id INTEGER NOT NULL REFERENCES tools(id) ON DELETE CASCADE
);
SQL,
        // 4: bepul sinov muddati 14 kundan 30 kunga (eski standart qiymatdagi mahsulotlar yangilanadi; berilgan sinov litsenziyalari o'zgarmaydi)
        4 => <<<'SQL'
UPDATE tools SET trial_days = 30 WHERE trial_days = 14;
SQL,
        // 5: brauzer demosining versiyalari (manzil yoki zip paket); tools.demo_id — joriy versiya (NULL: eski usul, faqat web_url)
        5 => <<<'SQL'
CREATE TABLE tool_demos (
  id INTEGER PRIMARY KEY,
  tool_id INTEGER NOT NULL REFERENCES tools(id) ON DELETE CASCADE,
  version TEXT NOT NULL,
  notes TEXT NOT NULL DEFAULT '',
  kind TEXT NOT NULL CHECK (kind IN ('url','package')),
  url TEXT NOT NULL DEFAULT '',
  file TEXT NOT NULL DEFAULT '',
  size INTEGER NOT NULL DEFAULT 0,
  sha256 TEXT NOT NULL DEFAULT '',
  created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
  created_at INTEGER NOT NULL,
  UNIQUE (tool_id, version)
);
ALTER TABLE tools ADD COLUMN demo_id INTEGER REFERENCES tool_demos(id) ON DELETE SET NULL;
SQL,
    ];

    public static function ensure(): void
    {
        $pdo = Db::pdo();
        $v = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
        foreach (self::MIGRATIONS as $n => $sql) {
            if ($n <= $v) {
                continue;
            }
            $pdo->exec('BEGIN IMMEDIATE');
            try {
                $pdo->exec($sql);
                $pdo->exec('PRAGMA user_version = ' . $n);
                $pdo->exec('COMMIT');
            } catch (Throwable $e) {
                try {
                    $pdo->exec('ROLLBACK');
                } catch (Throwable) {
                    // tranzaksiya allaqachon yopilgan
                }
                throw $e;
            }
        }
    }
}
