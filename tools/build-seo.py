#!/usr/bin/env python3
"""What search engines and link previews need, for the real domain.

Run from the site root, last in the build (after stamp-assets, so the 404
page copies index.html's fresh asset links):   python tools/build-seo.py

  * every page: <link rel="canonical"> and Open Graph / Twitter tags (title,
    description, image for a shared link), in a marked block in <head> that
    each run replaces;
  * sitemap.xml: every page a search engine should list (a page marked
    noindex, such as the guide's thank-you page, is left out);
  * robots.txt: everything open but the build's own folders, and the sitemap;
  * 404.html: the "page not found" page, in the site's shell. It is served
    for any missing address at any depth, so its links start at the root.

The address is SITE_URL: the one version of the domain (https, www) that the
host redirects every other version to (see .htaccess, tools/build-redirects.py).
"""

import html
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SITE_URL = 'https://www.bio-green.co.il/'
SITE_NAME = 'ביו-גרין'
DEFAULT_IMAGE = 'assets/img/og-default.jpg'          # 1200x630
SKIP = {'.git', '.github', 'tools', 'data', 'assets', 'a11y', 'node_modules'}
START, END = '<!-- seo:start (tools/build-seo.py) -->', '<!-- seo:end -->'

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from cms import attr, plain  # noqa: E402


def pages():
    out = []
    for base, dirs, files in os.walk(ROOT):
        dirs[:] = sorted(d for d in dirs if d not in SKIP)
        if 'index.html' in files:
            rel = os.path.relpath(base, ROOT).replace(os.sep, '/')
            out.append(('' if rel == '.' else rel + '/', os.path.join(base, 'index.html')))
    return sorted(out)


def image_size(path):
    try:
        from PIL import Image
        with Image.open(os.path.join(ROOT, path)) as im:
            return im.size
    except Exception:
        return None


def share_image(src, page_dir):
    """The picture a shared link shows: the product's photo, the article's
    picture, otherwise the site's own (the home hero with the logo)."""
    for pat in (r'<div class="pp-hero__media">\s*<img\b[^>]*>', r'<img class="article__image"[^>]*>'):
        m = re.search(pat, src)
        if m:
            s = attr(re.search(r'<img\b[^>]*>', m.group(0)).group(0), 'src') or ''
            path = os.path.normpath(os.path.join(page_dir, s.split('?')[0])).replace(os.sep, '/')
            if s and not s.startswith(('http', 'data:')) and os.path.exists(os.path.join(ROOT, path)):
                return path
    return DEFAULT_IMAGE


def head_block(src, rel, page_dir):
    url = SITE_URL + rel
    title = plain(re.search(r'<title\b[^>]*>(.*?)</title>', src, re.S).group(1))
    desc_tag = re.search(r'<meta name="description"[^>]*>', src)
    desc = re.sub(r'\s+', ' ', attr(desc_tag.group(0), 'content') or '').strip() if desc_tag else ''
    img = share_image(src, page_dir)
    kind = 'article' if re.search(r'<img class="article__image"', src) else 'website'
    a = lambda s: html.escape(s, quote=True)
    lines = [START,
             f'<link rel="canonical" href="{a(url)}">',
             f'<meta property="og:type" content="{kind}">',
             f'<meta property="og:site_name" content="{a(SITE_NAME)}">',
             '<meta property="og:locale" content="he_IL">',
             f'<meta property="og:url" content="{a(url)}">',
             f'<meta property="og:title" content="{a(title)}">']
    if desc:
        lines.append(f'<meta property="og:description" content="{a(desc)}">')
    lines.append(f'<meta property="og:image" content="{a(SITE_URL + img)}">')
    size = image_size(img)
    if size:
        lines += [f'<meta property="og:image:width" content="{size[0]}">',
                  f'<meta property="og:image:height" content="{size[1]}">']
    lines += ['<meta name="twitter:card" content="summary_large_image">', END]
    return '\n'.join(lines)


def set_head(src, block):
    src = re.sub(re.escape(START) + r'.*?' + re.escape(END) + r'\n?', '', src, flags=re.S)
    return src.replace('</head>', block + '\n</head>', 1)


def noindex(src):
    m = re.search(r'<meta name="robots" content="([^"]*)"', src)
    return bool(m and 'noindex' in m.group(1))


def rooted(fragment):
    """Document-relative URLs -> from the site root (the 404 page is served
    at whatever depth the missing address had)."""
    def fix(m):
        name, url = m.group(1), m.group(2)
        if url.startswith(('#', '/', 'http', 'mailto:', 'tel:', 'data:', 'javascript:')):
            return m.group(0)
        return f'{name}="/{url}"' if url != './' else f'{name}="/"'
    return re.sub(r'\b(href|src|data-statement)="([^"]*)"', fix, fragment)


NOT_FOUND = '''<main id="main">
<section class="page-hero page-hero--tight not-found">
  <div class="shell page-hero__inner">
    <span class="bg-eyebrow"><span class="bg-eyebrow__dot"></span>שגיאה 404</span>
    <h1>העמוד לא נמצא</h1>
    <p class="page-hero__sub">ייתכן שהכתובת השתנתה או שהעמוד הוסר. אפשר להמשיך מכאן:</p>
    <div class="btn-row">
      <a class="bg-btn bg-btn--primary bg-btn--lg" href="/">לדף הבית</a>
      <a class="bg-btn bg-btn--secondary bg-btn--lg" href="/products/">לקטלוג המוצרים</a>
      <a class="bg-btn bg-btn--ghost bg-btn--lg" href="/contact/">צור קשר</a>
    </div>
  </div>
</section>
</main>'''


def build_404():
    src = open(os.path.join(ROOT, 'index.html'), encoding='utf-8').read()
    a, b = src.index('<main id="main">'), src.index('</main>') + len('</main>')
    page = src[:a] + NOT_FOUND + src[b:]
    page = re.sub(r'<script type="application/ld\+json">.*?</script>\n*', '', page, flags=re.S)
    page = re.sub(re.escape(START) + r'.*?' + re.escape(END) + r'\n?', '', page, flags=re.S)
    page = re.sub(r'<title\b[^>]*>.*?</title>', f'<title>העמוד לא נמצא | {SITE_NAME}</title>', page, count=1, flags=re.S)
    page = re.sub(r'<meta name="description"[^>]*>\n', '', page, count=1)
    page = page.replace('</title>', '</title>\n<meta name="robots" content="noindex">', 1)
    body = re.search(r'<body\b[^>]*>', page).group(0)
    page = page.replace(body, '<body data-page-label="עמוד 404">', 1)
    # No editing marks: this is not a page of the content editor.
    page = re.sub(r'\s(?:data-cms(?:-[a-z-]+)?)(?:="[^"]*")?(?=[\s>])', '', page)
    page = page.replace(' aria-current="page"', '')
    return rooted(page)


def main():
    listed = []
    changed = []
    for rel, path in pages():
        src = open(path, encoding='utf-8').read()
        new = set_head(src, head_block(src, rel, rel.rstrip('/') or '.'))
        if new != src:
            open(path, 'w', encoding='utf-8', newline='\n').write(new)
            changed.append(rel or '/')
        if not noindex(new):
            listed.append(SITE_URL + rel)

    outputs = {
        'sitemap.xml': '<?xml version="1.0" encoding="UTF-8"?>\n'
                       '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n'
                       + ''.join(f'  <url><loc>{html.escape(u)}</loc></url>\n' for u in listed)
                       + '</urlset>\n',
        'robots.txt': 'User-agent: *\nDisallow: /tools/\nDisallow: /data/\n\n'
                      f'Sitemap: {SITE_URL}sitemap.xml\n',
        '404.html': build_404(),
    }
    for name, text in outputs.items():
        path = os.path.join(ROOT, name)
        old = open(path, encoding='utf-8').read() if os.path.exists(path) else None
        if old != text:
            open(path, 'w', encoding='utf-8', newline='\n').write(text)
            changed.append(name)
    print(f'seo: {len(listed)} page(s) in the sitemap; '
          + ('updated: ' + ', '.join(changed) if changed else 'nothing to update'))


if __name__ == '__main__':
    main()
