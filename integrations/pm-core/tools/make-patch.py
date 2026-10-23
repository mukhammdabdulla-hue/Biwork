#!/usr/bin/env python3
"""pm-connector.patch ni PM ning toza nusxasidan qayta yasaydi (PM yangilanganda):  python3 tools/make-patch.py <PM_papka>
Patch faqat 4 faylga tegadi (index.php, php/auth.php, php/hisobot-pdf.php, assets/app.js); har almashtirish aniq bir marta uchrashi shart — aks holda to'xtaydi."""
import os, shutil, subprocess, sys, tempfile

SRC = os.path.abspath(sys.argv[1])
HERE = os.path.dirname(os.path.abspath(__file__))
OUT = os.path.join(HERE, '..', 'pm-connector.patch')

EDITS = {
  'index.php': [
    ("require __DIR__ . '/php/db.php';\nrequire __DIR__ . '/php/auth.php';\nrequire __DIR__ . '/php/api.php';\n",
     "/* Biwork ulanishi (biwork-config.php bo'lsagina ishlaydi): demo rejimida DATA_DIR ni db.php'dan OLDIN tanlaydi */\n"
     "if (is_file(__DIR__ . '/php/biwork.php')) require __DIR__ . '/php/biwork.php';\n"
     "require __DIR__ . '/php/db.php';\nrequire __DIR__ . '/php/auth.php';\nrequire __DIR__ . '/php/api.php';\n"),
    ("$method = $_SERVER['REQUEST_METHOD'];\n",
     "$method = $_SERVER['REQUEST_METHOD'];\n\n/* Biwork: portaldan \"Ochish\", /licenziya, litsenziya darvozasi, brend */\n"
     "if (function_exists('biwork_route')) biwork_route($uri, $method);\n"),
    ("    $html = str_replace('{{ASSET_V}}', asset_version(), $html);\n",
     "    $html = str_replace('{{ASSET_V}}', asset_version(), $html);\n    if (function_exists('biwork_html')) $html = biwork_html($html);\n"),
  ],
  'php/auth.php': [
    ("function user_perms(array $u): array {\n    $rol = $u['role'] ?? 'user';",
     "function user_perms(array $u): array {\n"
     "    /* Biwork modul niqobi: ochiq modullardan tashqari hamma narsa 'none' (biwork.php; Biwork yoqilmagan bo'lsa o'zgarishsiz) */\n"
     "    $p = user_perms_xom($u);\n    return function_exists('biwork_mask_perms') ? biwork_mask_perms($p) : $p;\n}\n"
     "function user_perms_xom(array $u): array {\n    $rol = $u['role'] ?? 'user';"),
    ("        'bolimlar' => user_perms($u),\n",
     "        'bolimlar' => user_perms($u),\n"
     "        /* Biwork: modul niqobi faol — mijoz admin/rahbar rolini ham me.bolimlar bo'yicha cheklaydi */\n"
     "        'bwMask' => function_exists('biwork_modules') && biwork_modules() !== null,\n"),
  ],
  'php/hisobot-pdf.php': [
    ("    $r['kundalik'] = ['📔 Ish kundaligi', 'Korxona faoliyati: nima qilindi, qachon, kim'];\n",
     "    /* Biwork: kundalik moduli ochiq bo'lmagan mahsulotda (HR, Yuridik…) bu hisobot ko'rinmaydi */\n"
     "    if (!function_exists('biwork_has') || biwork_has('kundalik'))\n"
     "        $r['kundalik'] = ['📔 Ish kundaligi', 'Korxona faoliyati: nima qilindi, qachon, kim'];\n"),
  ],
  'assets/app.js': [
    ("    if(u.role==='admin' || u.role==='rahbar') return 'edit';".replace("'", '"'),
     "    if((u.role===\"admin\" || u.role===\"rahbar\") && !u.bwMask) return \"edit\";"),
  ],
}

def main():
    tmp = tempfile.mkdtemp()
    a, b = os.path.join(tmp, 'a'), os.path.join(tmp, 'b')
    for rel, edits in EDITS.items():
        s = open(os.path.join(SRC, rel), encoding='utf-8', newline='').read()
        os.makedirs(os.path.dirname(os.path.join(a, rel)), exist_ok=True)
        os.makedirs(os.path.dirname(os.path.join(b, rel)), exist_ok=True)
        open(os.path.join(a, rel), 'w', encoding='utf-8', newline='').write(s)
        for old, new in edits:
            n = s.count(old)
            if rel == 'assets/app.js':          # ikki joyda bir xil qator (sectionPerm, sectionKira)
                if n != 2: sys.exit(f"{rel}: kutilgan 2 ta, topildi {n}: {old!r}")
            elif n != 1:
                sys.exit(f"{rel}: kutilgan 1 ta, topildi {n}: {old[:60]!r}")
            s = s.replace(old, new)
        open(os.path.join(b, rel), 'w', encoding='utf-8', newline='').write(s)
    r = subprocess.run(['diff', '-ruN', 'a', 'b'], cwd=tmp, capture_output=True)
    out = r.stdout.decode('utf-8')   # CRLF saqlanadi (app.js, Main.html — Windows qator oxirlari)
    # vaqt belgilarini olib tashlaymiz (patch har safar bir xil chiqsin)
    lines = [l.split('\t')[0] if l.startswith(('--- ', '+++ ')) else l for l in out.split('\n')]
    open(OUT, 'w', encoding='utf-8', newline='').write('\n'.join(lines))
    shutil.rmtree(tmp)
    print('yozildi:', os.path.normpath(OUT))

main()
