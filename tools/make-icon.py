#!/usr/bin/env python3
"""Make a product hero icon: a line glyph with a Hebrew caption under it.

Run from the site root:   python tools/make-icon.py

The hero icons (assets/icons/product/) carry their caption as vector outlines,
so the words look the same everywhere and need no web font inside an <img>.
This script draws new ones in the same style: the caption is set in Heebo
(weight 800) from assets/fonts and turned into outlines, under either a glyph taken
from an existing icon (its caption dropped) or a glyph drawn here.

Each icon it writes goes to both places the site reads icons from:
  assets/icons/product/               the site
  tools/wordpress-plugin/icons/       the picker in the WordPress editor
and has to be listed in biogreen_icons() in the plugin, with its caption.

Icons 13-18 were redrawn by hand afterwards (15/11/2026: the caption in the
same green as the glyph), so the files in the repo are the client's, not this
script's output. It never overwrites an existing icon unless run with --force.

Needs fontTools and brotli (pip install fonttools brotli). Not part of the
build; run it by hand when a new icon is needed.
"""

import os
import re
import sys

from fontTools.pens.boundsPen import BoundsPen
from fontTools.pens.svgPathPen import SVGPathPen
from fontTools.pens.transformPen import TransformPen
from fontTools.ttLib import TTFont
from fontTools.varLib.instancer import instantiateVariableFont

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT_DIRS = [os.path.join(ROOT, 'assets', 'icons', 'product'),
            os.path.join(ROOT, 'tools', 'wordpress-plugin', 'icons')]
GREEN = '#0B8F5A'

# Measured on the existing icons: Hebrew letters stand ~23 units tall, the
# caption starts ~35 under the glyph, lines are 42 apart, strokes ~7.5 wide.
LETTER_H = 23.3
GAP = 35
LINE_PITCH = 42
MARGIN = 8
STROKE = 7.5
DRAWN_H = 185          # height a glyph drawn here is scaled to

# The Heebo files are the variable font (100-900, default 400) whatever their
# name says; the existing captions are set at about 800.
WEIGHT = 800
FONTS = [instantiateVariableFont(TTFont(os.path.join(ROOT, 'assets', 'fonts', f)),
                                 {'wght': WEIGHT})
         for f in ('heebo-700-hebrew.woff2', 'heebo-700-latin.woff2')]


def font_for(ch):
    for f in FONTS:
        if ord(ch) in f.getBestCmap():
            return f
    raise SystemExit(f'no glyph for {ch!r} in the Heebo subsets')


def visual(text):
    """Right-to-left text in left-to-right drawing order. Numbers and Latin
    words keep their own direction inside the reversed line."""
    runs = re.findall(r'[0-9A-Za-z.%®]+|[^0-9A-Za-z.%®]+', text)
    out = []
    for r in reversed(runs):
        out.append(r if re.match(r'[0-9A-Za-z]', r) else r[::-1])
    return ''.join(out)


def hebrew_height():
    f = FONTS[0]
    gs = f.getGlyphSet()
    p = BoundsPen(gs)
    gs[f.getBestCmap()[ord('ש')]].draw(p)
    return p.bounds[3]


SCALE = LETTER_H / hebrew_height()


def caption_line(text):
    """Outlines for one line, origin at its baseline start, and its width."""
    parts, x = [], 0.0
    for ch in visual(text):
        f = font_for(ch)
        name = f.getBestCmap()[ord(ch)]
        adv = f['hmtx'][name][0]
        pen = SVGPathPen(f.getGlyphSet())
        # Font units are y-up; SVG is y-down.
        f.getGlyphSet()[name].draw(TransformPen(pen, (SCALE, 0, 0, -SCALE, x, 0)))
        if pen.getCommands():
            parts.append(pen.getCommands())
        x += adv * SCALE
    return ' '.join(parts), x


def glyph_from(icon, below=210):
    """The glyph of an existing icon: every outline that starts above its
    caption. Returns (path data, x0, y0, x1, y1)."""
    s = open(os.path.join(OUT_DIRS[0], icon), encoding='utf-8').read()
    d = re.search(r'\sd="([^"]+)"', s).group(1)
    keep, xs, ys = [], [], []
    for sp in re.findall(r'M[^M]*', d):
        n = list(map(float, re.findall(r'-?\d+(?:\.\d+)?', sp)))
        if min(n[1::2]) < below:
            keep.append(sp.strip())
            xs += n[0::2]
            ys += n[1::2]
    return ' '.join(keep), min(xs), min(ys), max(xs), max(ys)


def fmt(v):
    return f'{v:.2f}'.rstrip('0').rstrip('.')


def write(name, caption_lines, glyph):
    """glyph is ('borrow', icon_file) or ('draw', svg_markup, box_w, box_h)."""
    lines = [caption_line(t) for t in caption_lines]
    text_w = max(w for _, w in lines)

    if glyph[0] == 'borrow':
        d, gx0, gy0, gx1, gy1 = glyph_from(glyph[1])
        gw, gh = gx1 - gx0, gy1 - gy0
    else:
        _, markup, bw, bh = glyph
        k = DRAWN_H / bh
        gw, gh = bw * k, bh * k

    width = max(gw, text_w) + 2 * MARGIN
    gy = MARGIN
    gx = (width - gw) / 2
    if glyph[0] == 'borrow':
        g = (f'<path transform="translate({fmt(gx - gx0)} {fmt(gy - gy0)})" d="{d}"/>')
    else:
        # Stroke width is set in the glyph's own units so it scales with it
        # and lands at STROKE after the scale.
        g = (f'<g transform="translate({fmt(gx)} {fmt(gy)}) scale({fmt(k)})" fill="none" '
             f'stroke="{GREEN}" stroke-width="{fmt(STROKE / k)}" stroke-linecap="round" '
             f'stroke-linejoin="round">{markup}</g>')

    base = gy + gh + GAP + LETTER_H
    text = []
    for i, (d, w) in enumerate(lines):
        text.append(f'<path transform="translate({fmt((width - w) / 2)} {fmt(base + i * LINE_PITCH)})" d="{d}"/>')
    height = base + (len(lines) - 1) * LINE_PITCH + MARGIN + 2

    svg = (f'<?xml version="1.0" encoding="UTF-8"?>\n'
           f'<svg xmlns="http://www.w3.org/2000/svg" width="{round(width)}" height="{round(height)}" '
           f'viewBox="0 0 {fmt(width)} {fmt(height)}" fill="none">\n'
           f'  <!-- {" / ".join(caption_lines)} — made by tools/make-icon.py -->\n'
           f'  <g fill="{GREEN}" fill-rule="evenodd" clip-rule="evenodd" shape-rendering="geometricPrecision">\n'
           f'    {g}\n    ' + '\n    '.join(text) + '\n  </g>\n</svg>\n')
    if not FORCE and any(os.path.exists(os.path.join(d, name)) for d in OUT_DIRS):
        print(f'{name}  exists, left alone (--force to redraw)')
        return
    for out in OUT_DIRS:
        with open(os.path.join(out, name), 'w', encoding='utf-8', newline='\n') as f:
            f.write(svg)
    print(f'{name}  {round(width)}x{round(height)}  {" / ".join(caption_lines)}')


# ---------------------------------------------------------------- the icons

# Drawn glyphs, in a 180x180 box.
FLAG = ('<path d="M22 172V10"/>'
        '<rect x="22" y="18" width="146" height="100" rx="8"/>'
        '<circle cx="95" cy="68" r="25" fill="' + GREEN + '" stroke="none"/>')

DOUBLE = ('<rect x="10" y="10" width="160" height="160" rx="14"/>'
          '<rect x="10" y="100" width="70" height="70" rx="10"/>'
          '<path d="M98 82L142 38"/><path d="M110 38H142V70"/>')

MINT = ('<path d="M90 174C90 140 88 108 82 78"/>'
        '<path d="M86 118C52 122 20 98 14 58C50 52 82 76 86 118Z"/>'
        '<path d="M86 118L40 76"/>'
        '<path d="M84 90C92 52 122 24 166 18C168 62 140 90 84 90Z"/>'
        '<path d="M84 90L136 44"/>')

ICONS = [
    ('13_size_x2.svg',        ['פי 2 בגודל'],    ('draw', DOUBLE, 180, 180)),
    ('14_made_in_japan_he.svg', ['תוצרת יפן'],   ('draw', FLAG, 180, 182)),
    ('15_night_use.svg',      ['לשימוש בלילה'], ('borrow', '09_night_time.svg')),
    ('16_registered_amr.svg', ['רשום באמ״ר'],   ('borrow', '12_medical_quality.svg')),
    ('17_patent.svg',         ['פטנט רשום'],     ('borrow', '08_sporilife_patent.svg')),
    ('18_mint.svg',           ['מנטה'],          ('draw', MINT, 180, 180)),
]

FORCE = '--force' in sys.argv

if __name__ == '__main__':
    for name, caption, glyph in ICONS:
        write(name, caption, glyph)
