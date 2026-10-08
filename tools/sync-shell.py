#!/usr/bin/env python3
"""Copy the shared chrome from index.html into the other static pages.

Run from the site root:   python tools/sync-shell.py

index.html is the master for four blocks that every page repeats:

    sprite   the inline icon symbols
    header   skip link + <header>…</header>
    footer   <footer>…</footer>
    modal    the quote modal, the floating WhatsApp, the mobile bar and the
             <script src=…/js/site.js> that follows them

Pages one directory deep get ../ added to their document-relative URLs, and the
nav item matching the page is marked aria-current="page".

Product pages are not listed here — tools/build-product-pages.py regenerates
them from index.html anyway.
"""

import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

# page -> the nav href that should be marked current (at the page's own depth)
TARGETS = {
    'import-regulation/index.html': 'import-regulation/',
    'products/index.html': 'products/',
    'contact/index.html': 'contact/',
}

# block name -> (first line starts with, last line contains)
# A head may be a tuple of alternatives, tried in order: the footer block now
# opens with the quick-contact band, but a page synced before the band existed
# still opens at <footer>, and the first sync has to find it to replace it.
# The tail is a substring test, not startswith: the same block ends with a
# deeper path ("../js/…") on pages one directory down.
BOUNDS = {
    'sprite': ('<svg xmlns="http://www.w3.org/2000/svg" hidden', '</svg>'),
    'header': ('<a class="skip-link"', '</header>'),
    'footer': (('<section class="quick-contact"', '<footer class="site-footer">'),
               '</footer>'),
    # ends on the a11y panel tag, which follows site.js
    'modal':  ('<div class="modal" id="quote-modal"', 'a11y-panel.js'),
}


def locate(lines, name):
    """Return the inclusive 0-based line span of a block, or exit with why not."""
    head, tail = BOUNDS[name]
    heads = head if isinstance(head, tuple) else (head,)
    a = None
    for h in heads:
        a = next((i for i, l in enumerate(lines) if l.startswith(h)), None)
        if a is not None:
            break
    if a is None:
        sys.exit(f'  cannot find the start of {name!r} ({heads!r})')
    try:
        b = next(i for i, l in enumerate(lines[a:], a) if tail in l)
    except StopIteration:
        sys.exit(f'  cannot find the end of {name!r} ({tail!r})')
    return a, b


def deepen(html, up):
    """Rewrite document-relative URLs for a page `up` deep ('' or '../')."""
    if not up:
        return html

    def fix(m):
        attr, url = m.group(1), m.group(2)
        if url.startswith(('#', '/', 'http', 'mailto:', 'tel:', 'data:', '..')):
            return m.group(0)
        return f'{attr}="{up}"' if url == './' else f'{attr}="{up}{url}"'

    # data-statement holds a URL too, just not in an href/src attribute.
    return re.sub(r'\b(href|src|data-statement)="([^"]*)"', fix, html)


def set_current(header, href):
    """Move aria-current="page" onto the nav item for this page."""
    header = re.sub(r'(<a class="nav__link"[^>]*?) aria-current="page"', r'\1', header)
    pat = f'<a class="nav__link" href="{href}">'
    if pat not in header:
        sys.exit(f'  no nav link for {href!r}')
    return header.replace(pat, f'<a class="nav__link" href="{href}" aria-current="page">', 1)


def main():
    src = open(os.path.join(ROOT, 'index.html'), encoding='utf-8').read().split('\n')
    blocks = {}
    for name in BOUNDS:
        a, b = locate(src, name)
        blocks[name] = '\n'.join(src[a:b + 1])

    changed = []
    for page, current in TARGETS.items():
        path = os.path.join(ROOT, page)
        if not os.path.exists(path):
            print(f'{page}: skipped (missing)')
            continue

        up = '../' * page.count('/')
        text = open(path, encoding='utf-8').read()

        for name in BOUNDS:
            lines = text.split('\n')
            a, b = locate(lines, name)
            new = deepen(blocks[name], up)
            if name == 'header':
                new = set_current(new, up + current)
            text = '\n'.join(lines[:a] + new.split('\n') + lines[b + 1:])

        before = open(path, encoding='utf-8').read()
        if text != before:
            open(path, 'w', encoding='utf-8', newline='\n').write(text)
            changed.append(page)

    print('synced from index.html: ' + (', '.join(changed) if changed else 'nothing to do'))
    if changed:
        print('note: run tools/build-products.py afterwards — the catalogue grid '
              'sits inside the synced page.')


if __name__ == '__main__':
    main()
