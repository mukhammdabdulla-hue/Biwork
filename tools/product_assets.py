#!/usr/bin/env python3
"""
Mahsulot reklama sahifasi uchun rasmlarni tayyorlaydi (Pillow kerak: pip install pillow).

  python3 tools/product_assets.py <slug> \
      --shot gantt=/yo'l/f02-gantt.png --shot tahlil=/yo'l/j04.png ... \
      [--mask 113,30,64,16] \
      [--og-title "Biwork Reja"] [--og-tagline "Qisqa tavsif"] [--og-shot gantt]

Natija: portal/public/assets/products/<slug>/<nom>.webp (har ekran rasmi, 1600 px gacha) va og.png (1200x630,
ijtimoiy tarmoqda ulashish ko'rinishi). Manifestda rasmlar shu nomlar bilan ko'rsatiladi (marketing.shots[].file).

--mask x,y,w,h : rasmdagi to'rtburchakni atrofdagi fon rangi bilan yopadi (masalan yon panel tagidagi korxona nomi
                 yozuvi). Koordinatalar rasmning asl piksellarida; yon panel fon rangi qatorma-qator olinadi.
"""
import argparse
import os
import sys

try:
    from PIL import Image, ImageDraw, ImageFilter, ImageFont
except ImportError:
    sys.exit("XATO: Pillow o'rnatilmagan (python3 -m pip install pillow)")

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
ASSETS = os.path.join(ROOT, 'portal', 'public', 'assets')
NAVY, NAVY2, GREEN = (4, 28, 49), (11, 46, 76), (29, 197, 144)
FONT_DIRS = ['/usr/share/fonts/opentype/inter', '/usr/share/fonts/truetype/inter', '/usr/share/fonts/truetype/dejavu']


def font(weight: str, size: int):
    for d in FONT_DIRS:
        for name in ('Inter-%s.otf' % weight, 'Inter-%s.ttf' % weight, 'DejaVuSans-Bold.ttf' if weight != 'Regular' else 'DejaVuSans.ttf'):
            p = os.path.join(d, name)
            if os.path.isfile(p):
                return ImageFont.truetype(p, size)
    return ImageFont.load_default()


def mask(im: Image.Image, box):
    x, y, w, h = box
    px = im.load()
    ref = min(im.width - 1, x + w + 20)
    for yy in range(y, min(im.height, y + h)):
        c = px[ref, yy]
        for xx in range(x, min(im.width, x + w)):
            px[xx, yy] = c
    return im


def wrap(draw, text, fnt, width):
    lines, cur = [], ''
    for word in text.split():
        t = (cur + ' ' + word).strip()
        if draw.textlength(t, font=fnt) <= width:
            cur = t
        else:
            lines.append(cur)
            cur = word
    if cur:
        lines.append(cur)
    return lines


def og_card(out, title, tagline, shot, pill="30 kun bepul sinab ko'ring"):
    W, H = 1200, 630
    # fon: yengil gradient + yashil yorug'lik
    g = Image.new('RGB', (W, H), NAVY)
    gp = g.load()
    for y in range(H):
        t = y / H
        for x in range(W):
            k = (x / W) * 0.55 + t * 0.45
            gp[x, y] = tuple(int(NAVY[i] + (NAVY2[i] - NAVY[i]) * k) for i in range(3))
    card = g
    glow = Image.new('RGB', (W, H), (0, 0, 0))
    ImageDraw.Draw(glow).ellipse((700, -260, 1400, 330), fill=(10, 159, 115))
    glow = glow.filter(ImageFilter.GaussianBlur(120))
    card = Image.blend(card, Image.composite(glow, card, glow.convert('L')), 0.55)
    d = ImageDraw.Draw(card)
    logo = Image.open(os.path.join(ASSETS, 'biwork-logo-white.png')).convert('RGBA')
    lh = 46
    logo = logo.resize((int(logo.width * lh / logo.height), lh), Image.LANCZOS)
    card.paste(logo, (64, 56), logo)
    f_title, f_tag, f_pill = font('Bold', 76), font('Regular', 30), font('SemiBold', 24)
    lines = wrap(d, title, f_title, 520)
    y = 190
    for ln in lines:
        d.text((64, y), ln, font=f_title, fill=(255, 255, 255))
        y += 88
    y += 10
    for ln in wrap(d, tagline, f_tag, 490)[:4]:
        d.text((66, y), ln, font=f_tag, fill=(185, 199, 216))
        y += 42
    pw = int(d.textlength(pill, font=f_pill)) + 44
    py = H - 110
    d.rounded_rectangle((64, py, 64 + pw, py + 54), radius=27, fill=GREEN)
    d.text((64 + 22, py + 12), pill, font=f_pill, fill=(3, 37, 25))
    if shot is not None:
        sw = 720
        s = shot.resize((sw, round(shot.height * sw / shot.width)), Image.LANCZOS).convert('RGB')
        s = s.crop((0, 0, sw, min(s.height, 470)))
        pos = (612, 128)
        shadow = Image.new('RGBA', (W, H), (0, 0, 0, 0))
        ImageDraw.Draw(shadow).rounded_rectangle((pos[0], pos[1] + 18, pos[0] + s.width, pos[1] + s.height + 18), radius=14, fill=(0, 0, 0, 170))
        shadow = shadow.filter(ImageFilter.GaussianBlur(22))
        card = Image.alpha_composite(card.convert('RGBA'), shadow).convert('RGB')
        rm = Image.new('L', s.size, 0)
        ImageDraw.Draw(rm).rounded_rectangle((0, 0, s.width - 1, s.height - 1), radius=14, fill=255)
        card.paste(s, pos, rm)
        ImageDraw.Draw(card).rounded_rectangle((pos[0], pos[1], pos[0] + s.width - 1, pos[1] + s.height - 1), radius=14, outline=(255, 255, 255), width=1)
    card.save(out, 'PNG', optimize=True)


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument('slug')
    ap.add_argument('--shot', action='append', default=[], metavar='NOM=FAYL')
    ap.add_argument('--mask', metavar='x,y,w,h')
    ap.add_argument('--max-width', type=int, default=1600)
    ap.add_argument('--og-title')
    ap.add_argument('--og-tagline', default='')
    ap.add_argument('--og-shot', help="--shot nomi yoki rasm fayli yo'li")
    ap.add_argument('--og-pill', default="30 kun bepul sinab ko'ring", help='yashil tugma yozuvi')
    ap.add_argument('--og-out', help="og.png o'rniga boshqa yo'lga saqlash")
    a = ap.parse_args()

    out = os.path.join(ASSETS, 'products', a.slug)
    os.makedirs(out, exist_ok=True)
    box = tuple(int(v) for v in a.mask.split(',')) if a.mask else None
    imgs = {}
    for spec in a.shot:
        name, _, path = spec.partition('=')
        if not name or not os.path.isfile(path):
            sys.exit('XATO: rasm topilmadi: %s' % spec)
        im = Image.open(path).convert('RGB')
        if box:
            im = mask(im, box)
        if im.width > a.max_width:
            im = im.resize((a.max_width, round(im.height * a.max_width / im.width)), Image.LANCZOS)
        dest = os.path.join(out, name + '.webp')
        im.save(dest, 'WEBP', quality=82, method=6)
        imgs[name] = im
        print('%s  %dx%d  %d KB' % (dest, im.width, im.height, os.path.getsize(dest) // 1024))
    if a.og_title:
        shot = imgs.get(a.og_shot) if a.og_shot else None
        if a.og_shot and shot is None and os.path.isfile(a.og_shot):
            shot = Image.open(a.og_shot).convert('RGB')
        if a.og_shot and shot is None:
            sys.exit("XATO: --og-shot '%s' --shot ro'yxatida ham, fayl sifatida ham yo'q" % a.og_shot)
        dest = a.og_out or os.path.join(out, 'og.png')
        og_card(dest, a.og_title, a.og_tagline, shot, a.og_pill)
        print('%s  1200x630  %d KB' % (dest, os.path.getsize(dest) // 1024))


if __name__ == '__main__':
    main()
