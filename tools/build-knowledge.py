#!/usr/bin/env python3
"""The knowledge hub's article cards, and each article's dates and schema.

Run from the site root, after tools/cms.py apply:   python tools/build-knowledge.py

An article is a page under knowledge/ whose <body> carries
data-article-category. Publishing one is adding its page; there is no list
to keep in step:

    cards     knowledge/index.html gets one card per article, from the
              article's <meta name="bg-card-title"> and "bg-card-text"
              (both edited in WordPress with the article), in the colour and
              with the icon of its category. A category with no article gets
              no tab.
    dates     the "עודכן" line under the title. The article's text is
              hashed on every build; when the hash changes, the update date
              becomes today's. The first build sets the publication date too.
    reading   minutes, from the word count.
    schema    the Article JSON-LD, from the H1, the meta description and
              the dates above.
"""

import datetime
import hashlib
import html
import json
import math
import os
import re

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
HUB = os.path.join(ROOT, 'knowledge', 'index.html')
SITE_URL = 'https://www.biogreen.co.il/'
WORDS_PER_MINUTE = 150      # Hebrew prose; gives the spec's 5 minutes for the first article
ORG = 'ביוגרין מוצרים טבעיים בע״מ'

# Order of the tabs; colours are the site's (sky, green tint, sand).
CATEGORIES = {
    'import':   ('יבוא ורגולציה', 'file-check'),
    'products': ('מוצרים, רכיבים וטכנולוגיות ספיגה', 'flask-conical'),
    'quality':  ('כשרות ואיכות', 'shield-check'),
}


def text(fragment):
    fragment = re.sub(r'<br\s*/?>', ' ', fragment)
    return re.sub(r'\s+', ' ', html.unescape(re.sub(r'<[^>]+>', ' ', fragment))).strip()


def meta(src, name):
    m = re.search(r'<meta name="%s"[^>]*\scontent="([^"]*)"' % re.escape(name), src)
    return html.unescape(m.group(1)) if m else ''


def between(src, name, body):
    return re.sub(r'(<!-- %s:start -->\n).*?(<!-- %s:end -->)' % (name, name),
                  lambda m: m.group(1) + body + m.group(2), src, flags=re.S)


def today():
    # The client works in Israel; a build just after midnight UTC is already
    # the next day there.
    now = datetime.datetime.now(datetime.timezone.utc) + datetime.timedelta(hours=3)
    return now.date().isoformat()


def articles():
    found = []
    for name in sorted(os.listdir(os.path.join(ROOT, 'knowledge'))):
        path = os.path.join(ROOT, 'knowledge', name, 'index.html')
        if not os.path.isfile(path):
            continue
        src = open(path, encoding='utf-8').read()
        body = re.search(r'<body\b[^>]*\sdata-article-category="([^"]+)"', src)
        if body and body.group(1) in CATEGORIES:
            found.append((name, body.group(1), path))
    return found


def update_article(path):
    """Dates, reading time and schema for one article. Returns its page source."""
    src = open(path, encoding='utf-8').read()
    body = src[src.index('<article class="article">'):src.index('</article>')]
    # The words a reader sees, minus the line this function writes itself.
    words = text(re.sub(r'<p class="article__meta".*?</p>|<template.*?</template>', '', body, flags=re.S))
    digest = hashlib.sha1(words.encode('utf-8')).hexdigest()[:12]

    line = re.search(r'<p class="article__meta" data-hash="([^"]*)" data-published="([^"]*)">.*?</p>', src, re.S)
    old_hash, published = line.group(1), line.group(2)
    modified = re.search(r'<time datetime="([^"]*)"', line.group(0)).group(1)
    if digest != old_hash or not modified:
        modified = today()
    published = published or modified
    minutes = max(1, math.ceil(len(words.split()) / WORDS_PER_MINUTE))
    shown = datetime.date.fromisoformat(modified).strftime('%d.%m.%Y')
    src = src.replace(line.group(0),
                      f'<p class="article__meta" data-hash="{digest}" data-published="{published}">'
                      f'עודכן: <time datetime="{modified}">{shown}</time> · '
                      f'זמן קריאה: <span class="article__minutes">{minutes}</span> דקות</p>')

    rel = os.path.relpath(os.path.dirname(path), ROOT).replace(os.sep, '/') + '/'
    data = {
        '@context': 'https://schema.org', '@type': 'Article',
        'headline': text(re.search(r'<h1\b[^>]*>(.*?)</h1>', src, re.S).group(1)),
        'description': meta(src, 'description'),
        'inLanguage': 'he-IL',
        'datePublished': published, 'dateModified': modified,
        'author': {'@type': 'Organization', 'name': ORG},
        'publisher': {'@type': 'Organization', 'name': ORG},
        'mainEntityOfPage': SITE_URL + rel,
    }
    src = between(src, 'article:schema', '<script type="application/ld+json">\n'
                  + json.dumps(data, ensure_ascii=False, indent=2) + '\n</script>\n')
    return src


def card(slug, cat, src):
    label, icon = CATEGORIES[cat]
    title = html.escape(meta(src, 'bg-card-title') or text(re.search(r'<h1\b[^>]*>(.*?)</h1>', src, re.S).group(1)), quote=False)
    blurb = html.escape(meta(src, 'bg-card-text'), quote=False)
    return f'''      <article class="k-card k-card--{cat}" data-category="{cat}">
        <span class="k-card__icon"><svg class="icon" width="22" height="22" aria-hidden="true"><use href="#i-{icon}"></use></svg></span>
        <p class="k-card__cat">{html.escape(label, quote=False)}</p>
        <h3 class="k-card__title"><a href="{slug}/">{title}</a></h3>
        <p class="k-card__text">{blurb}</p>
        <span class="k-card__more" aria-hidden="true">קראו עוד<svg class="icon" width="18" height="18"><use href="#i-arrow-left"></use></svg></span>
      </article>
'''


def main():
    if not os.path.exists(HUB):
        print('knowledge: no hub page, nothing to do')
        return
    cards, used = [], []
    for slug, cat, path in articles():
        before = open(path, encoding='utf-8').read()
        src = update_article(path)
        if src != before:
            open(path, 'w', encoding='utf-8', newline='\n').write(src)
        cards.append((list(CATEGORIES).index(cat), card(slug, cat, src)))
        used.append(cat)

    tabs = '        <button class="bg-tab bg-tab--active" type="button" role="tab" aria-selected="true" data-filter="all">הכל</button>\n'
    for cat, (label, _) in CATEGORIES.items():
        if cat in used:
            tabs += (f'        <button class="bg-tab" type="button" role="tab" aria-selected="false" '
                     f'data-filter="{cat}">{html.escape(label, quote=False)}</button>\n')

    before = open(HUB, encoding='utf-8').read()
    src = between(before, 'knowledge:tabs', tabs)
    src = between(src, 'knowledge:cards', ''.join(c for _, c in sorted(cards, key=lambda c: c[0])))
    if src != before:
        open(HUB, 'w', encoding='utf-8', newline='\n').write(src)
    print(f'knowledge: {len(cards)} article(s)' + (', hub updated' if src != before else ''))


if __name__ == '__main__':
    main()
