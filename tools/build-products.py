#!/usr/bin/env python3
"""Render the product cards from data/products.json into the site's HTML.

Run from the site root:   python tools/build-products.py

The JSON is the single source of truth. This script rewrites only the regions
between the  <!-- products:NAME:start -->  and  <!-- products:NAME:end -->
markers, so everything else on those pages stays hand-written.

Regions it fills:
  index.html            products:featured   the three cards on the home page
  products/index.html   products:filters    the category tabs
                        products:all        the full catalogue grid
                        products:jsonld     ItemList structured data

Nothing here is required at runtime — the output is plain static HTML, which is
the point: the catalogue stays crawlable by search engines and AI answerers.
"""

import json
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DATA = os.path.join(ROOT, 'data', 'products.json')

TONES = {'natural', 'reg', 'commercial', 'neutral', 'sand'}


def load():
    with open(DATA, encoding='utf-8') as f:
        data = json.load(f)

    cats = {c['id']: c['label'] for c in data['categories']}
    products = [p for p in data['products'] if p.get('published', True)]

    seen = set()
    for p in products:
        for field in ('slug', 'name', 'category', 'image'):
            if not p.get(field):
                sys.exit(f"products.json: '{p.get('slug', '?')}' is missing '{field}'")
        if p['slug'] in seen:
            sys.exit(f"products.json: duplicate slug '{p['slug']}'")
        seen.add(p['slug'])
        if p['category'] not in cats:
            sys.exit(f"products.json: '{p['slug']}' has unknown category '{p['category']}'")
        for lab in p.get('labels', []):
            if lab.get('tone') not in TONES:
                sys.exit(f"products.json: '{p['slug']}' has unknown label tone "
                         f"'{lab.get('tone')}' (allowed: {', '.join(sorted(TONES))})")
        img = os.path.join(ROOT, p['image'])
        if not os.path.exists(img):
            sys.exit(f"products.json: '{p['slug']}' points at a missing image: {p['image']}")

    featured = sorted([p for p in products if p.get('featured')],
                      key=lambda p: p['featured'])
    return data['categories'], cats, products, featured


def esc(s):
    return (str(s).replace('&', '&amp;').replace('<', '&lt;')
            .replace('>', '&gt;').replace('"', '&quot;'))


def card(p, cats, prefix, indent='      '):
    """One product card. `prefix` is '' at the site root, '../' one level deep."""
    i = indent
    labels = '\n'.join(
        f'{i}      <span class="bg-label bg-label--{l["tone"]}">{esc(l["text"])}</span>'
        for l in p.get('labels', [])[:3])
    benefits = '\n'.join(
        f'{i}      <li><svg class="icon" width="17" height="17">'
        f'<use href="#i-check"></use></svg>{esc(b)}</li>'
        for b in p.get('benefits', [])[:3])

    labels_block = (f'\n{i}    <div class="product__labels">\n{labels}\n{i}    </div>'
                    if labels else '')

    return f'''{i}<article class="bg-card bg-card--interactive product" data-category="{esc(p['category'])}" data-slug="{esc(p['slug'])}">
{i}  <div class="product__media">
{i}    <img src="{prefix}{esc(p['image'])}" alt="{esc(p.get('imageAlt', p['name']))}" loading="lazy">{labels_block}
{i}  </div>
{i}  <div class="product__body">
{i}    <span class="bg-eyebrow" style="margin:0"><span class="bg-eyebrow__dot"></span>{esc(cats[p['category']])}</span>
{i}    <h3>{esc(p['name'])}</h3>
{i}    <ul class="product__benefits">
{benefits}
{i}    </ul>
{i}    <div class="product__cta">
{i}      <button class="bg-btn bg-btn--primary bg-btn--sm bg-btn--block" data-quote data-intent="products" data-product="{esc(p['name'])}">
{i}        <svg class="icon" width="17" height="17"><use href="#i-receipt"></use></svg><span>לקבלת הצעת מחיר</span>
{i}      </button>
{i}    </div>
{i}  </div>
{i}</article>'''


def tabs(categories, products):
    used = {p['category'] for p in products}
    out = ['      <button class="bg-tab bg-tab--active" type="button" role="tab"'
           ' aria-selected="true" data-filter="all">הכל</button>']
    for c in categories:
        if c['id'] not in used:
            continue
        out.append(f'      <button class="bg-tab" type="button" role="tab"'
                   f' aria-selected="false" data-filter="{esc(c["id"])}">{esc(c["label"])}</button>')
    return '\n'.join(out)


def jsonld(products, cats):
    items = [{
        '@type': 'ListItem',
        'position': n,
        'item': {
            '@type': 'Product',
            'name': p['name'],
            'category': cats[p['category']],
            'image': f"https://www.biogreen.co.il/{p['image']}",
            'description': ' · '.join(p.get('benefits', [])),
            'brand': {'@type': 'Brand', 'name': 'BioGreen'},
        },
    } for n, p in enumerate(products, 1)]
    doc = {'@context': 'https://schema.org', '@type': 'ItemList',
           'name': 'קטלוג מוצרים לעסקים', 'itemListElement': items}
    # The whole element is generated: HTML comments cannot live inside a
    # ld+json script without breaking the JSON, so the markers sit outside it.
    body = json.dumps(doc, ensure_ascii=False, indent=2)
    return '<script type="application/ld+json">\n' + body + '\n</script>'


def fill(path, region, content):
    full = os.path.join(ROOT, path)
    with open(full, encoding='utf-8') as f:
        html = f.read()
    start, end = f'<!-- products:{region}:start -->', f'<!-- products:{region}:end -->'
    if start not in html or end not in html:
        sys.exit(f'{path}: missing the {region} markers')
    pattern = re.compile(re.escape(start) + r'.*?' + re.escape(end), re.S)
    new = pattern.sub(lambda _: f'{start}\n{content}\n' + ' ' * _leading(html, start) + end,
                      html, count=1)
    if new != html:
        with open(full, 'w', encoding='utf-8', newline='\n') as f:
            f.write(new)
    return new != html


def _leading(html, marker):
    line_start = html.rfind('\n', 0, html.index(marker)) + 1
    return len(html[line_start:html.index(marker)])


def main():
    categories, cats, products, featured = load()

    if len(featured) != 3:
        print(f'note: {len(featured)} products are marked featured; the home page '
              f'grid is designed for 3.')

    changed = []
    if fill('index.html', 'featured',
            '\n'.join(card(p, cats, '') for p in featured)):
        changed.append('index.html (featured)')

    if fill('products/index.html', 'filters', tabs(categories, products)):
        changed.append('products/index.html (filters)')
    if fill('products/index.html', 'all',
            '\n'.join(card(p, cats, '../') for p in products)):
        changed.append('products/index.html (grid)')
    if fill('products/index.html', 'jsonld', jsonld(products, cats)):
        changed.append('products/index.html (json-ld)')

    print(f'{len(products)} products · {len(featured)} featured · '
          f'{len({p["category"] for p in products})} categories in use')
    print('updated: ' + (', '.join(changed) if changed else 'nothing (already current)'))


if __name__ == '__main__':
    main()
