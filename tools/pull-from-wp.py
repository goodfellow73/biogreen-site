#!/usr/bin/env python3
"""Pull products from a headless WordPress install into data/products.json.

    python tools/pull-from-wp.py

Configuration comes from the environment (GitHub repo secrets in CI):

    WP_URL              https://cms.example.com        (required)
    WP_USER             an editor's username           (optional)
    WP_APP_PASSWORD     that user's application password (optional)

Without credentials it reads the public REST API, which is enough when the
`product` post type is public.

WordPress is an EDITOR here, never a runtime dependency. This script runs at
build time only, and it downloads every image into assets/img/ so the published
site never requests anything from the WordPress host. If the site were to fetch
from WordPress in the browser we would lose the three things the static build
exists for: speed, security, and a catalogue search engines can read.

It also writes data/product-pages/<slug>.json — the eight-tab product page —
but only for products that carry page copy in WordPress. A product with no page
copy is left exactly as it is, so hand-authored pages survive until their
content is moved across.
"""

import base64
import io
import json
import os
import re
import sys
import urllib.error
import urllib.parse
import urllib.request

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
IMG_DIR = os.path.join(ROOT, 'assets', 'img')
OUT = os.path.join(ROOT, 'data', 'products.json')
PAGE_DIR = os.path.join(ROOT, 'data', 'product-pages')

WP_URL = (os.environ.get('WP_URL') or '').rstrip('/')
WP_USER = os.environ.get('WP_USER') or ''
WP_PASS = os.environ.get('WP_APP_PASSWORD') or ''

TONES = {'natural', 'reg', 'commercial', 'neutral', 'sand'}
MAX_IMAGE_WIDTH = 900          # product shots are never shown larger than this
JPEG_QUALITY = 82


def die(msg):
    sys.exit('pull-from-wp: ' + msg)


def api(path, **params):
    if not WP_URL:
        die('WP_URL is not set.')
    url = f'{WP_URL}/wp-json/wp/v2/{path}'
    if params:
        url += '?' + urllib.parse.urlencode(params)

    req = urllib.request.Request(url, headers={'Accept': 'application/json'})
    if WP_USER and WP_PASS:
        token = base64.b64encode(f'{WP_USER}:{WP_PASS}'.encode()).decode()
        req.add_header('Authorization', 'Basic ' + token)

    try:
        with urllib.request.urlopen(req, timeout=30) as r:
            return json.loads(r.read().decode('utf-8'))
    except urllib.error.HTTPError as e:
        die(f'{url} returned HTTP {e.code}. '
            f'Check that the post type is registered with show_in_rest => true.')
    except urllib.error.URLError as e:
        die(f'cannot reach {url}: {e.reason}')


def clean(html):
    """WordPress returns rendered HTML even for plain fields."""
    if not html:
        return ''
    text = re.sub(r'<[^>]+>', '', str(html))
    for a, b in [('&amp;', '&'), ('&quot;', '"'), ('&#039;', "'"),
                 ('&lt;', '<'), ('&gt;', '>'), ('&nbsp;', ' ')]:
        text = text.replace(a, b)
    return text.strip()


def grab_image(url, slug):
    """Download an image into assets/img/ and return its repo-relative path.

    The editor will upload whatever their camera produced. Resizing here is what
    keeps a 4 MB photo from reaching the site.
    """
    if not url:
        return None

    ext = os.path.splitext(urllib.parse.urlparse(url).path)[1].lower()
    if ext not in ('.jpg', '.jpeg', '.png', '.webp'):
        ext = '.jpg'
    name = f'product-{slug}{".jpg" if ext != ".png" else ".png"}'
    dest = os.path.join(IMG_DIR, name)

    try:
        req = urllib.request.Request(url, headers={'User-Agent': 'biogreen-build'})
        with urllib.request.urlopen(req, timeout=60) as r:
            blob = r.read()
    except Exception as e:
        die(f'could not download {url}: {e}')

    try:
        from PIL import Image
    except ImportError:
        # Still better to ship the original than to fail the build.
        with open(dest, 'wb') as f:
            f.write(blob)
        print(f'  ! Pillow missing — saved {name} unoptimised '
              f'({len(blob)/1024:.0f} KB)')
        return f'assets/img/{name}'

    im = Image.open(io.BytesIO(blob))
    if im.mode in ('RGBA', 'P', 'LA') and ext == '.png':
        im = im.convert('RGBA')
        save_args = {}
        fmt = 'PNG'
    else:
        if im.mode != 'RGB':
            bg = Image.new('RGB', im.size, (255, 255, 255))
            im = im.convert('RGBA')
            bg.paste(im, mask=im.split()[-1])
            im = bg
        save_args = {'quality': JPEG_QUALITY, 'optimize': True, 'progressive': True}
        fmt = 'JPEG'

    if im.width > MAX_IMAGE_WIDTH:
        h = round(im.height * MAX_IMAGE_WIDTH / im.width)
        im = im.resize((MAX_IMAGE_WIDTH, h), Image.LANCZOS)

    im.save(dest, fmt, **save_args)
    print(f'  image {name}  {im.width}x{im.height}  '
          f'{os.path.getsize(dest)/1024:.0f} KB (was {len(blob)/1024:.0f} KB)')
    return f'assets/img/{name}'


def field(post, *names):
    """Read a value from ACF or from plain post meta, whichever is present."""
    acf = post.get('acf') or {}
    meta = post.get('meta') or {}
    for n in names:
        for src in (acf, meta):
            if n in src and src[n] not in ('', None, []):
                v = src[n]
                return v[0] if isinstance(v, list) and v else v
    return ''


# --------------------------------------------------------------- page content

# The eight tabs are the same on every product page; only their contents differ.
TABS = [
    ('description', 'תיאור'),
    ('audience',    'למי מתאים'),
    ('benefits',    'יתרונות'),
    ('usage',       'הוראות שימוש'),
    ('ingredients', 'רכיבים ומידע'),
    ('kosher',      'כשרות ואישורים'),
    ('label',       'תווית'),
    ('faq',         'שאלות נפוצות'),
]

PROSE_TABS = {'description', 'audience', 'usage', 'ingredients', 'kosher'}


def blocks_from_text(raw):
    """Parse a textarea into title/body pairs.

    Items are separated by a blank line; the first line of each is the title and
    the rest is the body:

        ספיגה עוצמתית עד פי 185
        הפורמולה מבוססת על טכנולוגיית…

        זמינות ל-24 שעות
        הטכנולוגיה מאפשרת…

    One field instead of two dozen numbered ones, and no ACF Pro repeater.
    """
    out = []
    for chunk in re.split(r'\n\s*\n', (raw or '').replace('\r\n', '\n').strip()):
        lines = [l.strip() for l in chunk.split('\n') if l.strip()]
        if not lines:
            continue
        out.append({'title': lines[0], 'text': ' '.join(lines[1:])})
    return out


def build_page(post, slug, name):
    """Assemble data/product-pages/<slug>.json, or None if WP has no page copy."""
    if not clean(field(post, 'page_lede')):
        return None

    icons = []
    for i in range(1, 7):
        f = clean(field(post, f'hero_icon_{i}'))
        if not f:
            continue
        if not os.path.exists(os.path.join(ROOT, 'assets', 'icons', 'product', f)):
            die(f"'{slug}' references a product icon that is not in the repo: {f}")
        icons.append({'file': f, 'alt': clean(field(post, f'hero_icon_{i}_alt')) or f})

    tabs = []
    for tid, label in TABS:
        heading = clean(field(post, f'tab_{tid}_heading'))
        blocks = []

        if tid in PROSE_TABS:
            body = field(post, f'tab_{tid}_body')
            if body:
                blocks.append({'type': 'html', 'value': str(body)})

        if tid == 'usage':
            n_title = clean(field(post, 'tab_usage_notice_title'))
            if n_title:
                items = [l.strip() for l in
                         str(field(post, 'tab_usage_notice_items') or '').split('\n')
                         if l.strip()]
                blocks.append({
                    'type': 'notice', 'title': n_title,
                    'text': clean(field(post, 'tab_usage_notice_text')),
                    'items': items,
                    'footer': clean(field(post, 'tab_usage_notice_footer')),
                })

        if tid == 'benefits':
            items = blocks_from_text(field(post, 'tab_benefits_items'))
            if items:
                blocks.append({'type': 'cards', 'items': items})

        if tid == 'faq':
            items = [{'q': b['title'], 'a': b['text']}
                     for b in blocks_from_text(field(post, 'tab_faq_items'))]
            if items:
                blocks.append({'type': 'faq', 'items': items})

        if tid == 'label':
            url = field(post, 'tab_label_image')
            if isinstance(url, dict):              # ACF image field returns an object
                url = url.get('url')
            alt = clean(field(post, 'tab_label_alt')) or f'תווית {name}'
            src = grab_image(url, f'{slug}-label') if url else None
            blocks.append({'type': 'image', 'src': src, 'alt': alt,
                           'placeholder': 'צילום תווית המוצר באיכות גבוהה'})

        if not heading and not blocks:
            continue                                # skip a tab WordPress left empty
        tabs.append({'id': tid, 'label': label,
                     'heading': heading or label, 'blocks': blocks})

    return {
        'slug': slug,
        'subtitle': clean(field(post, 'page_subtitle')),
        'tagline': clean(field(post, 'page_tagline')),
        'lede': clean(field(post, 'page_lede')),
        'heroIcons': icons,
        'tabs': tabs,
        'quote': {
            'heading': clean(field(post, 'quote_heading')) or f'רוצים לקבל הצעת מחיר ל{name}?',
            'sub': clean(field(post, 'quote_sub'))
                   or 'השאירו פרטים ונחזור אליכם עם מידע נוסף והצעת מחיר.',
            'privacy': 'הפרטים שמסרת ישמשו לצורך טיפול בפנייתך וליצירת קשר בנושא הפנייה. '
                       'לפרטים נוספים ראו מדיניות הפרטיות.',
            'consent': 'אני מעוניין/ת לקבל מביוגרין עדכונים, מידע מקצועי והצעות.',
        },
    }


def main():
    print(f'Reading products from {WP_URL}')

    terms = api('product_category', per_page=100)
    categories = [{'id': t['slug'], 'label': clean(t['name'])} for t in terms]
    by_term_id = {t['id']: t['slug'] for t in terms}

    posts = api('product', per_page=100, status='publish', _embed=1)
    if not isinstance(posts, list):
        die('unexpected response shape for the product list.')
    if not posts:
        # Refusing here is deliberate: a misconfigured endpoint should not be
        # able to silently empty the catalogue on the live site.
        die('WordPress returned zero published products. Refusing to overwrite '
            'the catalogue — fix the endpoint, or unpublish products one by one '
            'if that is really intended.')

    products, pages = [], []
    for p in posts:
        slug = urllib.parse.unquote(p.get('slug') or '')
        name = clean((p.get('title') or {}).get('rendered'))
        if not slug or not name:
            die(f'post {p.get("id")} has no slug or title.')

        # A Hebrew title gives WordPress a Hebrew slug, which it percent-encodes.
        # That string would become the public URL and the data filename, so ask
        # for a Latin one instead of quietly producing %d7%aa%d7%95%d7%a1…
        if not re.fullmatch(r'[a-z0-9-]+', slug):
            die(f"'{name}' has the slug '{slug}'. Set a Latin slug in the "
                f"WordPress editor (lowercase letters, digits and hyphens) — "
                f"it becomes the page address, e.g. /products/curcumin-185/.")

        term_ids = (p.get('product_category') or [])
        cat = by_term_id.get(term_ids[0]) if term_ids else None
        if not cat:
            die(f"'{slug}' has no product_category assigned.")

        media = ((p.get('_embedded') or {}).get('wp:featuredmedia') or [{}])[0]
        src = media.get('source_url')
        if not src:
            die(f"'{slug}' has no featured image.")
        image = grab_image(src, slug)
        alt = clean(field(p, 'image_alt')) or clean(media.get('alt_text')) or name

        labels = []
        for i in (1, 2, 3):
            text = clean(field(p, f'label_{i}_text'))
            if not text:
                continue
            tone = (clean(field(p, f'label_{i}_tone')) or 'natural').lower()
            if tone not in TONES:
                die(f"'{slug}' label {i} has tone '{tone}'. "
                    f"Allowed: {', '.join(sorted(TONES))}.")
            labels.append({'text': text, 'tone': tone})

        benefits = [clean(field(p, f'benefit_{i}')) for i in (1, 2, 3)]
        benefits = [b for b in benefits if b]

        order = field(p, 'featured_order')
        try:
            # An unset integer meta comes back from WordPress as 0, not ''.
            featured = int(order) if str(order).strip() else None
            if featured == 0:
                featured = None
        except ValueError:
            die(f"'{slug}' has a non-numeric featured_order: {order!r}")

        products.append({
            'slug': slug, 'name': name, 'category': cat,
            'image': image, 'imageAlt': alt,
            'labels': labels, 'benefits': benefits,
            'featured': featured, 'published': True,
        })
        print(f'  {slug:24} {name}')

        # Page content is optional: a product can exist in the catalogue without
        # a page of its own, exactly as it does today.
        page = build_page(p, slug, name)
        if page:
            os.makedirs(PAGE_DIR, exist_ok=True)
            with open(os.path.join(PAGE_DIR, slug + '.json'), 'w',
                      encoding='utf-8', newline='\n') as f:
                json.dump(page, f, ensure_ascii=False, indent=2)
                f.write('\n')
            print(f'  {"":24} page: {len(page["tabs"])} tabs, '
                  f'{len(page["heroIcons"])} icons')
            pages.append(slug)

    seen = set()
    for p in products:
        if p['slug'] in seen:
            die(f"duplicate slug '{p['slug']}'.")
        seen.add(p['slug'])

    featured = sorted([p for p in products if p['featured']], key=lambda p: p['featured'])
    if len(featured) != 3:
        print(f'  note: {len(featured)} products carry a featured_order; '
              f'the home page grid is built for 3.')

    used = {p['category'] for p in products}
    categories = [c for c in categories if c['id'] in used]

    existing = {}
    if os.path.exists(OUT):
        with open(OUT, encoding='utf-8') as f:
            existing = json.load(f)

    doc = {
        '_readme': existing.get('_readme', []),
        '_source': f'Generated by tools/pull-from-wp.py from {WP_URL}. '
                   f'Edit products in WordPress, not here.',
        'categories': categories,
        'products': products,
    }
    with open(OUT, 'w', encoding='utf-8', newline='\n') as f:
        json.dump(doc, f, ensure_ascii=False, indent=2)
        f.write('\n')

    print(f'\n{len(products)} products, {len(categories)} categories -> data/products.json')
    print('Now run: python tools/build-products.py')


if __name__ == '__main__':
    main()
