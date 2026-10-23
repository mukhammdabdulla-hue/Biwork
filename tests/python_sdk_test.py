#!/usr/bin/env python3
"""
Python SDK sinovi: PHP/portal imzolagan tokenlarni tekshirish (ikkala Ed25519 realizatsiyasi bilan), faollashtirish,
oflayn holatlar (soatni siljitish bilan), to'xtatish/tiklash, o'rindiqlar, "Ochish" tokeni.
Haqiqiy portal serveri kerak — odatda `bash tests/run.sh` ishga tushiradi.
Muhit: PORTAL, LICENSE_KEY (1 o'rindiqli reja litsenziyasi), LICENSE_ID, BIWORK_DATA (portal ma'lumotlari), BIWORK_CONFIG
"""
import json
import os
import shutil
import subprocess
import sys
import tempfile
import time
import urllib.request

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, os.path.join(ROOT, 'sdk', 'python'))
import biwork_license as bw  # noqa: E402

PORTAL = os.environ['PORTAL'].rstrip('/')
KEY = os.environ['LICENSE_KEY']
LID = int(os.environ['LICENSE_ID'])
fails = total = 0


def ok(cond, name):
    global fails, total
    total += 1
    print(('  ✓ ' if cond else '  ✗ ') + name)
    if not cond:
        fails += 1


def raises(fn, code, name):
    try:
        fn()
    except bw.BiworkException as e:
        ok(e.code == code, '%s → %s%s' % (name, code, '' if e.code == code else ' (olingan: %s — %s)' % (e.code, e.message)))
        return
    ok(False, '%s (xato kutilgan edi: %s)' % (name, code))


def php(code, **env):
    """Portal kodini ishga tushiradi (xuddi shu ma'lumotlar bazasi va kalitlar)."""
    r = subprocess.run(['php', '-r', 'require $argv[1]; ' + code, os.path.join(ROOT, 'portal', 'src', 'bootstrap.php')],
                       capture_output=True, text=True, env=dict(os.environ, **env))
    if r.returncode != 0:
        raise RuntimeError(r.stderr or r.stdout)
    return r.stdout.strip()


def launch_token(**over):
    claims = {'aud': 'reja', 'jti': os.urandom(8).hex(), 'iat': int(time.time()), 'exp': int(time.time()) + 120, 'mode': 'demo',
              'user': {'id': 1, 'name': 'Sinov', 'email': 's@example.uz', 'role': 'owner', 'tool_role': 'admin'}, 'cust': {'id': 1, 'name': 'X'}}
    claims.update(over)
    return php('echo Tokens::sign(Tokens::LAUNCH, json_decode(getenv("CLAIMS"), true));', CLAIMS=json.dumps(claims))


def clone(d):
    """Papkaning nusxasi: oflayn soat siljitish sinovlari asl faylga (max_seen) tegmasligi uchun."""
    n = tempfile.mkdtemp(prefix='bwpy-clone')
    shutil.copytree(d, n, dirs_exist_ok=True)
    return n


def fresh(name, **kw):
    d = tempfile.mkdtemp(prefix='bwpy-' + name)
    return bw.BiworkConnect(server=PORTAL, tool='reja', data_dir=d, public_keys=PUB, device_name=name, **kw), d


with urllib.request.urlopen(PORTAL + '/api/v1/pubkey') as r:
    PUB = json.load(r)

print('== Ed25519: sof Python realizatsiyasi')
# RFC 8032 §7.1, TEST 1 (bo'sh xabar)
pk = bytes.fromhex('d75a980182b10ab7d54bfed3c964073a0ee172f3daa62325af021a68f707511a')
sg = bytes.fromhex('e5564300c360ac729086e2cc806e828a84877f1eb8e5d974d873e065224901555fb8821590a33bacc61e39701cf9b46bd25bf5f0595bbe24655141438e7a100b')
ok(bw._ed25519_verify_pure(pk, b'', sg), 'RFC 8032 test vektori 1 (bo\'sh xabar) tekshiriladi')
ok(not bw._ed25519_verify_pure(pk, b'x', sg), "boshqa xabar rad etiladi")
bad = bytearray(sg)
bad[10] ^= 1
ok(not bw._ed25519_verify_pure(pk, b'', bytes(bad)), 'buzilgan imzo rad etiladi')
ok(not bw._ed25519_verify_pure(pk, b'', sg[:32] + (bw._Q + 1).to_bytes(32, 'little')), "s >= L bo'lgan imzo rad etiladi")

print("== PHP (portal) imzolagan tokenlarni Python tekshiradi")
keys = bw.load_keys(PUB)
ok(len(keys) >= 1, "ochiq kalitlar o'qildi (/api/v1/pubkey)")
tok = launch_token()
c = bw.verify_token('bwl1', tok, keys)
ok(c['aud'] == 'reja' and c['user']['name'] == 'Sinov', 'bwl1 tekshirildi (cryptography)')
parts = tok.split('.')
sig = bw._b64u_decode(parts[2])
pub = keys[c['kid']]
ok(bw._ed25519_verify_pure(pub, ('bwl1.' + parts[1]).encode(), sig), 'bir xil token sof Python bilan ham tekshirildi')
ok(bw._ed25519_verify(pub, ('bwl1.' + parts[1]).encode(), sig), 'cryptography bilan tekshirildi')
raises(lambda: bw.verify_token('bwk1', tok, keys), 'bad_token', "tur chalkashligi: 'Ochish' tokeni litsenziya o'rnida")
raises(lambda: bw.verify_token('bwl1', parts[0] + '.' + parts[1] + '.' + parts[2][:-4] + 'AAAA', keys), 'bad_signature', 'imzo buzildi')
raises(lambda: bw.verify_token('bwl1', 'bwl1.xx', keys), 'bad_token', 'token formati')
raises(lambda: bw.verify_token('bwl1', tok, {'boshqa': pub}), 'bad_signature', "noma'lum kid")

print("== 'Ochish' tokeni (verify_launch)")
cl, _ = fresh('launch')
c = cl.verify_launch(tok)
ok(c['user']['name'] == 'Sinov', 'token qabul qilindi')
raises(lambda: cl.verify_launch(tok), 'replay', 'ikkinchi marta ishlamaydi')
raises(lambda: cl.verify_launch(launch_token(aud='boshqa')), 'wrong_audience', 'boshqa vosita')
raises(lambda: cl.verify_launch(launch_token(exp=int(time.time()) - 5, iat=int(time.time()) - 130)), 'expired', 'muddati o\'tgan')
cl_late = bw.BiworkConnect(server=PORTAL, tool='reja', data_dir=cl.dir, public_keys=PUB, now=lambda: int(time.time()) + 200)
raises(lambda: cl_late.verify_launch(launch_token()), 'expired', "2 daqiqadan keyin (soat siljigan) eskiradi")

print("== Faollashtirish va holat")
A, dirA = fresh('A')
ok(A.state()['state'] == 'none', 'faollashtirishdan oldin: none')
raises(lambda: A.activate('BW-REJ-AAAA-BBBB-CCCC'), 'invalid_key', "noto'g'ri kalit")
st = A.activate(KEY.lower())
ok(st['allowed'] and st['state'] == 'valid', 'faollashtirildi (kichik harflar bilan ham)')
ok(st['license']['seats'] == 1 and st['license']['cust']['name'], "token ichida korxona va o'rindiqlar")
ok(st['days_left'] is not None and 360 <= st['days_left'] <= 366, 'muddat ~365 kun (%s)' % st['days_left'])
ok(A.device_id() == A.device_id() and len(A.device_id()) == 32, 'qurilma identifikatori barqaror')
ok(os.stat(os.path.join(dirA, 'biwork-license.json')).st_mode & 0o007 == 0, 'litsenziya fayli boshqalar uchun yopiq (chmod 640)')
B, dirB = fresh('B')
raises(lambda: B.activate(KEY), 'seats_full', "2-qurilma: o'rindiq band")
ok(A.refresh()['state'] == 'valid', 'davriy tekshiruv (refresh)')

print("== Oflayn holatlar (soat siljitildi, internet yo'q)")
offline = lambda m, u, b: (0, {})  # noqa: E731
T = int(time.time())
for days, want in ((10, 'valid'), (15, 'grace'), (22, 'locked')):
    X = bw.BiworkConnect(server=PORTAL, tool='reja', data_dir=clone(dirA), public_keys=PUB, device_name='A', now=lambda d=days: T + d * 86400, http=offline)
    s = X.ensure()
    ok(s['state'] == want and s['allowed'] == (want != 'locked'), '+%d kun: %s (%s)' % (days, s['state'], s['message']))
X = bw.BiworkConnect(server=PORTAL, tool='reja', data_dir=clone(dirA), public_keys=PUB, device_name='A', now=lambda: T - 3 * 86400, http=offline)
ok(X.state()['state'] == 'clock', "soat 3 kun orqaga surilsa: 'clock'")
ok(A.state()['state'] == 'valid', 'haqiqiy soat bilan hamon faol')
moved = clone(dirA)
with open(os.path.join(moved, 'biwork-device.id'), 'w') as fh:
    fh.write('0' * 32 + '\n')
M = bw.BiworkConnect(server=PORTAL, tool='reja', data_dir=moved, public_keys=PUB, device_name='A')
ok(M.state()['state'] == 'invalid', "papka boshqa o'rnatishga ko'chirilsa (qurilma izi o'zgarsa) litsenziya yaroqsiz")

print("== To'xtatish va tiklash")
A2, dirA2 = fresh('A2', app_version='1.8')
A.deactivate()  # o'rindiqni bo'shatamiz
ok(A.state()['state'] == 'none', "deactivate: mahalliy litsenziya o'chdi")
ok(A2.activate(KEY)['allowed'], "bo'shagan o'rindiqqa yangi qurilma faollashdi")
php("Licenses::setStatus(%d, 'suspended', 'system', 'py');" % LID)
raises(lambda: A2.refresh(), 'suspended', "to'xtatilgan litsenziya yangilanmaydi")
s = A2.state()
ok(s['state'] == 'blocked' and not s['allowed'], 'darhol bloklandi')
ok(A2.ensure()['state'] == 'blocked', "ensure() 15 daqiqa ichida qayta so'ramaydi")
php("Licenses::setStatus(%d, 'active', 'system');" % LID)
ok(A2.ensure()['state'] == 'blocked', 'tiklangan, lekin 15 daqiqa o\'tmagan: hali bloklangan')
A3 = bw.BiworkConnect(server=PORTAL, tool='reja', data_dir=dirA2, public_keys=PUB, device_name='A2', now=lambda: int(time.time()) + 16 * 60)
s = A3.ensure()
ok(s['allowed'] and s['state'] == 'valid', "15 daqiqadan keyin ensure() tiklangan litsenziyani o'zi ochadi")
php("Licenses::setStatus(%d, 'revoked', 'system');" % LID)
raises(lambda: A2.refresh(), 'revoked', 'bekor qilingan')
A4 = bw.BiworkConnect(server=PORTAL, tool='reja', data_dir=dirA2, public_keys=PUB, device_name='A2', now=lambda: int(time.time()) + 3600)
ok(A4.ensure()['state'] == 'blocked', "bekor qilingan litsenziya o'zi tiklanmaydi (faqat qayta faollashtirish bilan)")
php("Licenses::setStatus(%d, 'active', 'system');" % LID)
ok(A2.activate(KEY)['allowed'], 'qayta yoqilgach kalit bilan qayta ochiladi')
A2.deactivate()

print()
if fails == 0:
    print("HAMMASI O'TDI (%d)" % total)
    sys.exit(0)
print('XATOLAR: %d / %d' % (fails, total))
sys.exit(1)
