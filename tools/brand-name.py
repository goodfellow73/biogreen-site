#!/usr/bin/env python3
"""One spelling of the company's Hebrew name on every page: ביו-גרין.

Run from the site root, after every step that writes page text and before
build-faq-schema.py:   python tools/brand-name.py

The client asked (04.11.2026) for "ביו-גרין" everywhere — visible text, alt
text, schema and the legal name "ביו-גרין מוצרים טבעיים בע״מ". Much of the
text comes from WordPress (page texts, products, articles), where the older
"ביוגרין" or "ביו־גרין" (Hebrew maqaf) may already be saved or typed again
later. Rewriting the finished pages keeps the site consistent whatever was
saved; the code and templates use the right spelling to begin with.

Only these Hebrew spellings are touched. "BioGreen" and the domains are not.
"""

import os

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
BRAND = 'ביו-גרין'
VARIANTS = ('ביוגרין', 'ביו־גרין', 'ביו גרין')
SKIP = {'.git', '.github', 'tools', 'data', 'assets'}


def main():
    changed = 0
    for base, dirs, files in os.walk(ROOT):
        dirs[:] = [d for d in dirs if d not in SKIP]
        for name in files:
            if not name.endswith('.html'):
                continue
            path = os.path.join(base, name)
            before = open(path, encoding='utf-8').read()
            text = before
            for v in VARIANTS:
                text = text.replace(v, BRAND)
            if text != before:
                open(path, 'w', encoding='utf-8', newline='\n').write(text)
                changed += 1
    print(f'brand name: {BRAND} — {changed} page(s) corrected' if changed else f'brand name: {BRAND} everywhere already')


if __name__ == '__main__':
    main()
