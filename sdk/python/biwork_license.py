"""
Biwork Connect — vositani Biwork platformasiga ulash kutubxonasi (Python 3.8+).
Bitta fayl. Tashqi bog'liqlik shart emas: Ed25519 imzosi `cryptography` bo'lsa o'shandan, bo'lmasa shu fayldagi
sof Python (RFC 8032) tekshiruvidan foydalaniladi. Shartnoma: docs/TOOL_CONTRACT.md. PHP varianti bilan bir xil mantiq.

    bw = BiworkConnect(server='https://biwork.uz', tool='reja', data_dir='data',
                       public_keys='biwork-public.json', app_version='1.8')
    st = bw.ensure()              # har ishga tushishda: {'allowed': bool, 'state': ..., 'message': ...}
    bw.activate('BW-REJ-....')    # kalit kiritilganda
    claims = bw.verify_launch(token)   # portaldan "Ochish" orqali kelganda
"""
from __future__ import annotations

import base64
import hashlib
import json
import math
import os
import platform
import re
import socket
import tempfile
import time
import urllib.error
import urllib.request
from typing import Any, Callable, Dict, Optional, Union

__all__ = ['BiworkConnect', 'BiworkException', 'load_keys', 'verify_token']

VERSION = '1.0'
LICENSE = 'bwk1'
LAUNCH = 'bwl1'
RETRY_SEC = 900  # onlayn tekshiruv muddati o'tgach, yangilashga urinishlar orasidagi minimal vaqt
_BLOCKING = ('revoked', 'suspended', 'expired', 'invalid_key', 'not_activated', 'tool_mismatch', 'not_started')
_RECOVERABLE = ('suspended', 'expired', 'not_started')


class BiworkException(Exception):
    def __init__(self, code: str, message: str):
        super().__init__(message)
        self.code = code
        self.message = message


# ---------------------------------------------------------------------- Ed25519 (RFC 8032, faqat tekshirish)

_P = 2 ** 255 - 19
_Q = 2 ** 252 + 27742317777372353535851937790883648493
_D = -121665 * pow(121666, _P - 2, _P) % _P
_SQRT_M1 = pow(2, (_P - 1) // 4, _P)


def _recover_x(y: int, sign: int) -> Optional[int]:
    if y >= _P:
        return None
    x2 = (y * y - 1) * pow(_D * y * y + 1, _P - 2, _P) % _P
    if x2 == 0:
        return None if sign else 0
    x = pow(x2, (_P + 3) // 8, _P)
    if (x * x - x2) % _P != 0:
        x = x * _SQRT_M1 % _P
    if (x * x - x2) % _P != 0:
        return None
    if (x & 1) != sign:
        x = _P - x
    return x


def _add(a, b):
    A = (a[1] - a[0]) * (b[1] - b[0]) % _P
    B = (a[1] + a[0]) * (b[1] + b[0]) % _P
    C = 2 * a[3] * b[3] * _D % _P
    D = 2 * a[2] * b[2] % _P
    E, F, G, H = B - A, D - C, D + C, B + A
    return (E * F % _P, G * H % _P, F * G % _P, E * H % _P)


def _mul(s: int, pt):
    q = (0, 1, 1, 0)
    while s > 0:
        if s & 1:
            q = _add(q, pt)
        pt = _add(pt, pt)
        s >>= 1
    return q


def _decompress(b: bytes):
    if len(b) != 32:
        return None
    y = int.from_bytes(b, 'little')
    sign = y >> 255
    y &= (1 << 255) - 1
    x = _recover_x(y, sign)
    return None if x is None else (x, y, 1, x * y % _P)


_GY = 4 * pow(5, _P - 2, _P) % _P
_GX = _recover_x(_GY, 0)
_G = (_GX, _GY, 1, _GX * _GY % _P)


def _ed25519_verify_pure(public: bytes, message: bytes, sig: bytes) -> bool:
    if len(public) != 32 or len(sig) != 64:
        return False
    A = _decompress(public)
    R = _decompress(sig[:32])
    s = int.from_bytes(sig[32:], 'little')
    if A is None or R is None or s >= _Q:
        return False
    h = int.from_bytes(hashlib.sha512(sig[:32] + public + message).digest(), 'little') % _Q
    left = _mul(s, _G)
    right = _add(R, _mul(h, A))
    return (left[0] * right[2] - right[0] * left[2]) % _P == 0 and (left[1] * right[2] - right[1] * left[2]) % _P == 0


def _ed25519_verify(public: bytes, message: bytes, sig: bytes) -> bool:
    try:
        from cryptography.exceptions import InvalidSignature
        from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PublicKey
    except Exception:  # cryptography o'rnatilmagan
        return _ed25519_verify_pure(public, message, sig)
    try:
        Ed25519PublicKey.from_public_bytes(public).verify(sig, message)
        return True
    except (InvalidSignature, ValueError):
        return False


# ---------------------------------------------------------------------- tokenlar

def _b64u_decode(s: str) -> Optional[bytes]:
    try:
        return base64.urlsafe_b64decode(s + '=' * (-len(s) % 4))
    except Exception:
        return None


def load_keys(src: Union[str, dict, list]) -> Dict[str, bytes]:
    """`/api/v1/pubkey` javobi (dict), uning `keys` ro'yxati yoki shu JSON fayl yo'li -> {kid: 32 bayt}."""
    if isinstance(src, str):
        try:
            with open(src, 'r', encoding='utf-8') as f:
                src = json.load(f)
        except (OSError, ValueError):
            src = {}
    items = src.get('keys', src) if isinstance(src, dict) else src
    pairs = items.items() if isinstance(items, dict) else enumerate(items or [])
    out: Dict[str, bytes] = {}
    for k, v in pairs:
        if isinstance(v, dict) and 'kid' in v and 'public' in v:
            kid, pub = str(v['kid']), str(v['public'])
        else:
            kid, pub = str(k), str(v)
        raw = _b64u_decode(pub)
        if raw is not None and len(raw) == 32:
            out[kid] = raw
    return out


def verify_token(kind: str, token: str, keys: Dict[str, bytes]) -> dict:
    """Imzoni tekshiradi, payloadni qaytaradi. Muddatlarni tekshirmaydi."""
    parts = token.split('.')
    if len(parts) != 3 or parts[0] != kind:
        raise BiworkException('bad_token', "Token formati noto'g'ri")
    raw, sig = _b64u_decode(parts[1]), _b64u_decode(parts[2])
    try:
        payload = json.loads(raw) if raw is not None else None
    except ValueError:
        payload = None
    if not isinstance(payload, dict) or sig is None or len(sig) != 64:
        raise BiworkException('bad_token', 'Token buzilgan')
    pub = keys.get(str(payload.get('kid', '')))
    if pub is None or not _ed25519_verify(pub, (kind + '.' + parts[1]).encode('ascii'), sig):
        raise BiworkException('bad_signature', "Token imzosi noto'g'ri")
    return payload


# ---------------------------------------------------------------------- mijoz

class BiworkConnect:
    def __init__(self, server: str, tool: str, data_dir: str, public_keys: Union[str, dict, list], app_version: str = '',
                 device_name: Optional[str] = None, timeout: int = 8,
                 http: Optional[Callable[[str, str, dict], 'tuple[int, dict]']] = None, now: Optional[Callable[[], int]] = None):
        for name, val in (('server', server), ('tool', tool), ('data_dir', data_dir)):
            if not val:
                raise BiworkException('config', "BiworkConnect: '%s' sozlanmagan" % name)
        self.server, self.tool, self.dir = server.rstrip('/'), tool, data_dir.rstrip('/\\')
        self.app_version, self._device_name, self.timeout = app_version, device_name, timeout
        self._http, self._now = http, now
        self.keys = load_keys(public_keys)
        if not self.keys:
            raise BiworkException('config', 'Biwork ochiq kalitlari topilmadi (public_keys)')

    def now(self) -> int:
        return int(self._now()) if self._now else int(time.time())

    # ---- qurilma izi va saqlash
    def device_id(self) -> str:
        f = os.path.join(self.dir, 'biwork-device.id')
        try:
            with open(f, 'r', encoding='utf-8') as fh:
                inst = fh.read().strip()
        except OSError:
            inst = ''
        if not re.fullmatch(r'[a-f0-9]{32}', inst):
            inst = os.urandom(16).hex()
            self._ensure_dir()
            self._write(f, inst + '\n')
        machine = ''
        for m in ('/etc/machine-id', '/var/lib/dbus/machine-id'):
            try:
                with open(m, 'r', encoding='utf-8') as fh:
                    machine = fh.read().strip()
                break
            except OSError:
                continue
        return hashlib.sha256((inst + '|' + platform.node() + '|' + machine).encode()).hexdigest()[:32]

    def device_name(self) -> str:
        return self._device_name or platform.node() or socket.gethostname()

    def _ensure_dir(self) -> None:
        try:
            os.makedirs(self.dir, exist_ok=True)
        except OSError as e:
            raise BiworkException('storage', "Litsenziya papkasini yaratib bo'lmadi: %s (%s)" % (self.dir, e))

    @staticmethod
    def _write(path: str, text: str) -> None:
        d = os.path.dirname(path) or '.'
        fd, tmp = tempfile.mkstemp(prefix='.bw', dir=d)
        try:
            with os.fdopen(fd, 'w', encoding='utf-8') as fh:
                fh.write(text)
            try:
                os.chmod(tmp, 0o640)
            except OSError:
                pass
            os.replace(tmp, path)
        except OSError as e:
            try:
                os.unlink(tmp)
            except OSError:
                pass
            raise BiworkException('storage', "Saqlab bo'lmadi: %s (%s)" % (path, e))

    def _load(self) -> dict:
        try:
            with open(os.path.join(self.dir, 'biwork-license.json'), 'r', encoding='utf-8') as fh:
                d = json.load(fh)
            return d if isinstance(d, dict) else {}
        except (OSError, ValueError):
            return {}

    def _save(self, d: dict) -> None:
        self._ensure_dir()
        self._write(os.path.join(self.dir, 'biwork-license.json'), json.dumps(d, ensure_ascii=False, separators=(',', ':')))

    # ---- holat (oflayn)
    def state(self) -> dict:
        def res(ok, st, msg, lic=None, days=None, refresh=False):
            return {'allowed': ok, 'state': st, 'message': msg, 'license': lic, 'days_left': days, 'needs_refresh': refresh}

        d = self._load()
        if not d.get('token'):
            return res(False, 'none', 'Litsenziya faollashtirilmagan. Litsenziya kalitini kiriting.')
        try:
            c = verify_token(LICENSE, str(d['token']), self.keys)
        except BiworkException as e:
            return res(False, 'invalid', 'Litsenziya fayli yaroqsiz: %s. Kalitni qayta kiriting.' % e.message)
        t = self.tool.lower()
        if str(c.get('tool', '')).lower() != t and str(c.get('tool_code', '')).lower() != t:
            return res(False, 'invalid', 'Litsenziya boshqa vosita uchun.', c)
        if str(c.get('dev', '')) != self.device_id():
            return res(False, 'invalid', "Litsenziya boshqa qurilmaga bog'langan. Shu qurilmada kalitni qayta kiriting.", c)
        if d.get('blocked'):
            return res(False, 'blocked', str(d['blocked'].get('message') or 'Litsenziya bloklangan.'), c)
        now = self.now()
        seen = max(int(d.get('max_seen') or 0), int(d.get('server_time') or 0))
        if seen > 0 and now + 86400 < seen:
            return res(False, 'clock', "Kompyuter soati orqaga surilgan. Soatni to'g'rilab, internetga ulanib qayta urining.", c, None, True)
        if now > seen:
            d['max_seen'] = now
            try:
                self._save(d)
            except BiworkException:
                pass  # faqat o'qish rejimida ham ishlayversin
        exp = int(c['exp']) if c.get('exp') is not None else None
        days = None if exp is None else int(math.ceil((exp - now) / 86400))
        if int(c.get('nbf') or 0) > now + 86400:
            return res(False, 'invalid', 'Litsenziya hali boshlanmagan.', c, days)
        if exp is not None and now > exp:
            return res(False, 'expired', "Litsenziya muddati tugagan. Uzaytirish uchun Biwork bilan bog'laning.", c, days, True)
        chk, grace = int(c.get('chk') or 0), int(c.get('grace') or 0)
        if now > chk + grace:
            return res(False, 'locked', "Litsenziyani onlayn tekshirish muddati o'tdi. Internetga ulanib, qayta urining.", c, days, True)
        if now > chk:
            return res(True, 'grace', 'Litsenziyani onlayn tekshirish kerak (qolgan imtiyoz: %d kun).' % math.ceil((chk + grace - now) / 86400), c, days, True)
        return res(True, 'valid', 'Litsenziya faol.', c, days, now > chk - 86400)

    def ensure(self) -> dict:
        """Kerak bo'lsa onlayn yangilaydi; internet bo'lmasa imtiyoz muddati ichida ishlashda davom etadi."""
        st = self.state()
        d = self._load()
        if st['state'] == 'blocked':
            if str((d.get('blocked') or {}).get('code', '')) not in _RECOVERABLE:
                return st
        elif not st['needs_refresh'] or st['state'] in ('none', 'invalid'):
            return st
        if self.now() - int(d.get('last_attempt') or 0) < RETRY_SEC:
            return st
        try:
            return self.refresh()
        except BiworkException:
            return self.state()

    # ---- server bilan
    def activate(self, key: str) -> dict:
        r = self._call('activate', {'key': key, 'tool': self.tool, 'device_name': self.device_name(),
                                    'platform': platform.system() or 'Python', 'app_version': self.app_version})
        self._store(key, r)
        return self.state()

    def refresh(self) -> dict:
        d = self._load()
        if not d.get('key'):
            raise BiworkException('not_activated', 'Litsenziya faollashtirilmagan')
        d['last_attempt'] = self.now()
        self._save(d)
        try:
            r = self._call('check', {'key': d['key'], 'tool': self.tool, 'app_version': self.app_version})
        except BiworkException as e:
            if e.code in _BLOCKING:
                d = self._load()
                d['blocked'] = {'code': e.code, 'message': e.message, 'at': self.now()}
                self._save(d)
            raise
        self._store(str(d['key']), r)
        return self.state()

    def deactivate(self) -> None:
        d = self._load()
        if d.get('key'):
            try:
                self._call('deactivate', {'key': d['key']})
            finally:
                try:
                    os.unlink(os.path.join(self.dir, 'biwork-license.json'))
                except OSError:
                    pass

    def _store(self, key: str, r: dict) -> None:
        token = str(r.get('token', ''))
        c = verify_token(LICENSE, token, self.keys)  # imzosiz javobni saqlamaymiz
        if str(c.get('dev', '')) != self.device_id():
            raise BiworkException('bad_token', 'Server boshqa qurilma uchun token qaytardi')
        old = self._load()
        st = int(r.get('server_time') or 0)
        self._save({'key': key.strip().upper(), 'token': token, 'saved_at': self.now(), 'server_time': st,
                    'max_seen': max(int(old.get('max_seen') or 0), self.now(), st), 'last_attempt': self.now(), 'blocked': None})

    def _call(self, op: str, body: dict) -> dict:
        body = dict(body, device_id=self.device_id())
        url = '%s/api/v1/licenses/%s' % (self.server, op)
        status, js = self._http('POST', url, body) if self._http else self._post(url, body)
        if js.get('ok') is True:
            return js
        err = js.get('error') or {}
        if 'code' in err:
            raise BiworkException(str(err['code']), str(err.get('message') or 'Litsenziya xatosi'))
        raise BiworkException('network', "Litsenziya serveriga ulanib bo'lmadi (HTTP %s)" % status)

    def _post(self, url: str, body: dict) -> 'tuple[int, dict]':
        req = urllib.request.Request(url, data=json.dumps(body).encode(), method='POST',
                                     headers={'Content-Type': 'application/json', 'Accept': 'application/json'})
        try:
            with urllib.request.urlopen(req, timeout=self.timeout) as resp:
                status, raw = resp.status, resp.read()
        except urllib.error.HTTPError as e:
            status, raw = e.code, e.read()
        except (urllib.error.URLError, OSError):
            return 0, {}
        try:
            js = json.loads(raw)
        except ValueError:
            js = {}
        return status, js if isinstance(js, dict) else {}

    # ---- "Ochish" tokeni (portal -> vosita)
    def verify_launch(self, token: str) -> dict:
        """Imzo, auditoriya, muddat va takrorlanmaslikni tekshiradi (docs/TOOL_CONTRACT.md §4)."""
        p = verify_token(LAUNCH, token, self.keys)
        if str(p.get('aud', '')).lower() != self.tool.lower():
            raise BiworkException('wrong_audience', 'Token boshqa vosita uchun berilgan')
        now = self.now()
        if int(p.get('exp') or 0) < now or int(p.get('iat') or 2 ** 62) > now + 120:
            raise BiworkException('expired', 'Kirish havolasi eskirgan. Portaldan qayta oching.')
        self._ensure_dir()
        path = os.path.join(self.dir, 'biwork-jti.json')
        with open(path, 'a+', encoding='utf-8') as fh:
            _lock(fh)
            try:
                fh.seek(0)
                try:
                    seen = json.loads(fh.read() or '{}')
                except ValueError:
                    seen = {}
                seen = {k: v for k, v in (seen if isinstance(seen, dict) else {}).items() if int(v) > now}
                jti = str(p.get('jti') or '')
                if not jti or jti in seen:
                    raise BiworkException('replay', 'Kirish havolasi allaqachon ishlatilgan. Portaldan qayta oching.')
                seen[jti] = int(p['exp']) + 60
                fh.seek(0)
                fh.truncate()
                fh.write(json.dumps(seen))
                fh.flush()
            finally:
                _unlock(fh)
        return p


try:  # fayl qulfi: jti keshi bir vaqtda ikki so'rovda buzilmasligi uchun
    import fcntl

    def _lock(fh) -> None:
        fcntl.flock(fh.fileno(), fcntl.LOCK_EX)

    def _unlock(fh) -> None:
        fcntl.flock(fh.fileno(), fcntl.LOCK_UN)
except ImportError:  # Windows
    import msvcrt

    def _lock(fh) -> None:
        fh.seek(0)
        msvcrt.locking(fh.fileno(), msvcrt.LK_LOCK, 1)

    def _unlock(fh) -> None:
        fh.seek(0)
        try:
            msvcrt.locking(fh.fileno(), msvcrt.LK_UNLCK, 1)
        except OSError:
            pass
