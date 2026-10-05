#!/usr/bin/env python3
"""One-time migration: push the repo's products into WordPress.

    WP_URL=https://… WP_USER=… WP_APP_PASSWORD="xxxx xxxx …" \
        python tools/push-to-wp.py

Seeds a fresh WordPress install with what the site already has, so that
switching the source of truth over does not mean retyping six products and an
eight-tab page by hand.

Direction of travel is the opposite of tools/pull-from-wp.py: the structured
blocks in data/product-pages/*.json are flattened back into the editor fields
the plugin exposes. Running it twice updates rather than duplicates — products
are matched on their slug.

Never commit credentials. Pass them in the environment; the application
password can be revoked in WordPress the moment this finishes.
"""

import base64
import json
import mimetypes
import os
import sys
import urllib.error
import urllib.parse
import urllib.request

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PAGE_DIR = os.path.join(ROOT, 'data', 'product-pages')

WP_URL = (os.environ.get('WP_URL') or '').rstrip('/')
WP_USER = os.environ.get('WP_USER') or ''
WP_PASS = (os.environ.get('WP_APP_PASSWORD') or '').replace(' ', '')


def die(msg):
    sys.exit('push-to-wp: ' + msg)


if not (WP_URL and WP_USER and WP_PASS):
    die('WP_URL, WP_USER and WP_APP_PASSWORD are all required.')

AUTH = 'Basic ' + base64.b64encode(f'{WP_USER}:{WP_PASS}'.encode()).decode()


def api(path, data=None, method=None, raw=None, filename=None, ctype=None):
    url = f'{WP_URL}/wp-json/wp/v2/{path}'
    headers = {'Authorization': AUTH, 'Accept': 'application/json',
               'User-Agent': 'biogreen-migration'}
    body = None

    if raw is not None:
        body = raw
        headers['Content-Type'] = ctype or 'application/octet-stream'
        headers['Content-Disposition'] = f'attachment; filename="{filename}"'
    elif data is not None:
        body = json.dumps(data, ensure_ascii=False).encode('utf-8')
        headers['Content-Type'] = 'application/json; charset=utf-8'

    req = urllib.request.Request(url, data=body, headers=headers,
                                 method=method or ('POST' if body else 'GET'))
    try:
        with urllib.request.urlopen(req, timeout=120) as r:
            return json.loads(r.read().decode('utf-8'))
    except urllib.error.HTTPError as e:
        detail = e.read().decode('utf-8', 'replace')[:400]
        die(f'{method or "GET"} {path} -> HTTP {e.code}\n{detail}')
    except urllib.error.URLError as e:
        die(f'cannot reach {url}: {e.reason}')


def esc(s):
    return (str(s).replace('&', '&amp;').replace('<', '&lt;').replace('>', '&gt;'))


# ------------------------------------------- page blocks -> editor fields

def blocks_to_html(blocks):
    """Flatten our structured blocks into the HTML an editor field holds."""
    out = []
    for b in blocks:
        t = b.get('type')
        if t == 'html':
            out.append(b['value'].strip())
        elif t == 'text':
            out.append(f'<p>{esc(b["value"])}</p>')
        elif t == 'list':
            if b.get('title'):
                out.append(f'<h4>{esc(b["title"])}</h4>')
            items = ''.join(f'<li>{esc(i)}</li>' for i in b['items'])
            out.append(f'<ul>{items}</ul>')
        elif t == 'steps':
            if b.get('title'):
                out.append(f'<h4>{esc(b["title"])}</h4>')
            for s in b['items']:
                out.append(f'<h4>{esc(s["title"])}</h4><p>{esc(s["text"])}</p>')
        elif t == 'cards':
            for c in b['items']:
                out.append(f'<h4>{esc(c["title"])}</h4><p>{esc(c["text"])}</p>')
        elif t == 'note':
            out.append(f'<h4>{esc(b["title"])}</h4><p>{esc(b["text"])}</p>')
    return '\n'.join(out)


def pairs_to_text(items, a='title', b='text'):
    """The blank-line convention the plugin's textareas use."""
    return '\n\n'.join(f'{i[a]}\n{i[b]}' for i in items)


def page_meta(page):
    """Everything in data/product-pages/<slug>.json as flat editor fields."""
    meta = {
        'page_subtitle': page.get('subtitle', ''),
        'page_tagline': page.get('tagline', ''),
        'page_lede': page.get('lede', ''),
        'quote_heading': (page.get('quote') or {}).get('heading', ''),
        'quote_sub': (page.get('quote') or {}).get('sub', ''),
    }

    for n, icon in enumerate(page.get('heroIcons', [])[:6], 1):
        meta[f'hero_icon_{n}'] = icon['file']
        meta[f'hero_icon_{n}_alt'] = icon.get('alt', '')

    for tab in page.get('tabs', []):
        tid, blocks = tab['id'], tab.get('blocks', [])
        meta[f'tab_{tid}_heading'] = tab.get('heading', '')

        if tid == 'benefits':
            cards = [b for b in blocks if b.get('type') == 'cards']
            if cards:
                meta['tab_benefits_items'] = pairs_to_text(cards[0]['items'])

        elif tid == 'faq':
            faq = [b for b in blocks if b.get('type') == 'faq']
            if faq:
                meta['tab_faq_items'] = pairs_to_text(faq[0]['items'], 'q', 'a')

        elif tid == 'label':
            img = [b for b in blocks if b.get('type') == 'image']
            if img:
                meta['tab_label_alt'] = img[0].get('alt', '')

        elif tid == 'usage':
            meta['tab_usage_body'] = blocks_to_html(
                [b for b in blocks if b.get('type') != 'notice'])
            notice = [b for b in blocks if b.get('type') == 'notice']
            if notice:
                n = notice[0]
                meta['tab_usage_notice_title'] = n.get('title', '')
                meta['tab_usage_notice_text'] = n.get('text', '')
                meta['tab_usage_notice_items'] = '\n'.join(n.get('items', []))
                meta['tab_usage_notice_footer'] = n.get('footer', '')

        else:
            meta[f'tab_{tid}_body'] = blocks_to_html(blocks)

    return meta


# ------------------------------------------------------------------ upload

def upload_image(rel_path):
    """Put a repo image into the media library, reusing it if already there."""
    name = os.path.basename(rel_path)
    stem = os.path.splitext(name)[0]

    existing = api(f'media?search={urllib.parse.quote(stem)}&per_page=100')
    for m in existing:
        if m.get('slug') == stem or os.path.basename(m.get('source_url', '')) == name:
            return m['id'], m['source_url']

    full = os.path.join(ROOT, rel_path)
    if not os.path.exists(full):
        die(f'image not found: {rel_path}')
    with open(full, 'rb') as f:
        blob = f.read()

    ctype = mimetypes.guess_type(name)[0] or 'image/jpeg'
    m = api('media', raw=blob, filename=name, ctype=ctype, method='POST')
    return m['id'], m['source_url']


def main():
    print(f'Pushing to {WP_URL} as {WP_USER}\n')

    with open(os.path.join(ROOT, 'data', 'products.json'), encoding='utf-8') as f:
        data = json.load(f)

    # categories -------------------------------------------------------
    terms = {t['slug']: t['id'] for t in api('product_category?per_page=100')}
    for c in data['categories']:
        if c['id'] in terms:
            print(f"  category  {c['id']:16} exists")
            continue
        t = api('product_category', {'name': c['label'], 'slug': c['id']})
        terms[c['id']] = t['id']
        print(f"  category  {c['id']:16} created")

    existing = {p['slug']: p['id']
                for p in api('product?per_page=100&status=publish,draft')}

    print()
    for p in data['products']:
        slug = p['slug']
        media_id, _ = upload_image(p['image'])

        meta = {'image_alt': p.get('imageAlt', '')}
        # Registered as an integer, so an empty string is rejected outright —
        # omit the key for a product that is not featured.
        if p.get('featured'):
            meta['featured_order'] = int(p['featured'])
        for n, b in enumerate(p.get('benefits', [])[:3], 1):
            meta[f'benefit_{n}'] = b
        for n, l in enumerate(p.get('labels', [])[:3], 1):
            meta[f'label_{n}_text'] = l['text']
            meta[f'label_{n}_tone'] = l['tone']

        page_file = os.path.join(PAGE_DIR, slug + '.json')
        has_page = os.path.exists(page_file)
        if has_page:
            with open(page_file, encoding='utf-8') as f:
                meta.update(page_meta(json.load(f)))

        body = {
            'title': p['name'],
            'slug': slug,                      # explicit, or WP derives a Hebrew one
            'status': 'publish' if p.get('published', True) else 'draft',
            'featured_media': media_id,
            'product_category': [terms[p['category']]],
            'meta': meta,
        }

        if slug in existing:
            api(f'product/{existing[slug]}', body, method='POST')
            action = 'updated'
        else:
            created = api('product', body, method='POST')
            existing[slug] = created['id']
            action = 'created'

        print(f"  {slug:22} {action:8} {len(meta):>2} fields"
              f"{'  + full page' if has_page else ''}")

    print(f'\n{len(data["products"])} products in WordPress.')
    print('Revoke the application password now — it is no longer needed.')


if __name__ == '__main__':
    main()
