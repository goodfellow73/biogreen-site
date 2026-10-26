#!/usr/bin/env python3
"""Finish the glossary page after its texts come in from WordPress.

Run from the site root, after tools/cms.py apply:   python tools/build-glossary.py

The terms are edited in WordPress as lists, one per topic. Three things on
the page follow from those lists and are made here, so nobody keeps them in
step by hand:

    anchors   every term's <h3> carries an id so articles can link to it
              (…/knowledge/glossary/#importer). Existing ids never change; a
              new term gets one made from its name: its Latin words if it has
              any ("HS Code" -> hs-code), else the Hebrew name itself.
    tabs      the filter tabs read the topic titles, so renaming a topic in
              WordPress renames its tab.
    schema    the DefinedTermSet JSON-LD, rebuilt from the visible cards: the
              spec requires it to match the page word for word.
"""

import html
import json
import os
import re

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PAGE = os.path.join(ROOT, 'knowledge', 'glossary', 'index.html')
URL = 'https://www.biogreen.co.il/knowledge/glossary/'

TERM = re.compile(r'(<h3 class="term__name"[^>]*?\sid=")([^"]*)("[^>]*>)(.*?)(</h3>)', re.S)
DEF = re.compile(r'<p class="term__def"[^>]*>(.*?)</p>', re.S)


def text(fragment):
    fragment = re.sub(r'<br\s*/?>', ' ', fragment)
    return re.sub(r'\s+', ' ', html.unescape(re.sub(r'<[^>]+>', '', fragment))).strip()


def slug(name):
    latin = re.findall(r'[A-Za-z0-9]+', name)
    if latin:
        return '-'.join(latin).lower()
    return re.sub(r'\s+', '-', re.sub(r'[^\w\s-]', '', name).strip()) or 'term'


def anchors(src):
    terms = list(TERM.finditer(src))
    term_spans = {m.start(2) for m in terms}
    # Every other id on the page is taken too: the shell's, the sections'.
    taken = {m.group(1) for m in re.finditer(r'\sid="([^"]+)"', src)
             if m.start(1) not in term_spans}
    named = []
    for m in terms:
        cur = m.group(2)
        if not cur or cur in taken:
            base = slug(text(m.group(4)))
            cur, n = base, 2
            while cur in taken:
                cur, n = f'{base}-{n}', n + 1
        taken.add(cur)
        named.append(cur)
    for m, new in reversed(list(zip(terms, named))):
        src = src[:m.start(2)] + new + src[m.end(2):]
    return src


def tabs(src):
    for m in re.finditer(r'<section\b[^>]*\sdata-topic="([^"]+)"[^>]*>.*?<h2\b[^>]*>(.*?)</h2>', src, re.S):
        label = html.escape(text(m.group(2)), quote=False)
        src = re.sub(r'(<button class="bg-tab[^"]*"[^>]*\sdata-filter="%s">)[^<]*(</button>)' % re.escape(m.group(1)),
                     lambda t: t.group(1) + label + t.group(2), src)
    return src


def schema(src):
    h1 = re.search(r'<h1\b[^>]*>(.*?)</h1>', src, re.S)
    terms = []
    for card in re.finditer(r'<article class="term">(.*?)</article>', src, re.S):
        name, desc = TERM.search(card.group(1)), DEF.search(card.group(1))
        if name and desc:
            terms.append({'@type': 'DefinedTerm', 'name': text(name.group(4)),
                          'description': text(desc.group(1))})
    data = {'@context': 'https://schema.org', '@type': 'DefinedTermSet',
            'name': text(h1.group(1)), 'url': URL, 'hasDefinedTerm': terms}
    block = ('<!-- glossary:schema:start -->\n<script type="application/ld+json">\n'
             + json.dumps(data, ensure_ascii=False, indent=2)
             + '\n</script>\n<!-- glossary:schema:end -->')
    return re.sub(r'<!-- glossary:schema:start -->.*?<!-- glossary:schema:end -->',
                  lambda _: block, src, flags=re.S), len(terms)


def main():
    if not os.path.exists(PAGE):
        print('glossary: no page, nothing to do')
        return
    before = open(PAGE, encoding='utf-8').read()
    src, count = schema(tabs(anchors(before)))
    if src != before:
        open(PAGE, 'w', encoding='utf-8', newline='\n').write(src)
    print(f'glossary: {count} terms' + (', page updated' if src != before else ', already current'))


if __name__ == '__main__':
    main()
