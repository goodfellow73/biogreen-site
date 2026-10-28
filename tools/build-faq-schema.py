#!/usr/bin/env python3
"""FAQPage schema made from the questions a page actually shows.

Run from the site root, after tools/cms.py apply:   python tools/build-faq-schema.py

Google requires FAQ markup to match the visible questions and answers word for
word, and the client edits those in WordPress. So the JSON-LD is not written
by hand: on every page holding the two marker comments

    <!-- faq:schema:start -->
    <!-- faq:schema:end -->

it is rebuilt from the page's .bg-faq items — the question's
data-cms-slot="1", the answer's data-cms-slot="2". The soft link under an
answer is not part of the answer and stays out.
"""

import html
import json
import os
import re

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
BLOCK = re.compile(r'<!-- faq:schema:start -->.*?<!-- faq:schema:end -->', re.S)
ITEM = re.compile(r'<div class="bg-faq[^"]*">(.*?)<div class="bg-faq__a"[^>]*>(.*?)</div>', re.S)
SLOT = '<[a-z0-9]+\\b[^>]*\\sdata-cms-slot="%s"[^>]*>(.*?)</[a-z0-9]+>'


def text(fragment):
    fragment = re.sub(r'<br\s*/?>', ' ', fragment)
    return re.sub(r'\s+', ' ', html.unescape(re.sub(r'<[^>]+>', '', fragment))).strip()


def schema(src):
    questions = []
    for m in ITEM.finditer(src):
        q, a = re.search(SLOT % 1, m.group(1), re.S), re.search(SLOT % 2, m.group(2), re.S)
        if q and a:
            questions.append({'@type': 'Question', 'name': text(q.group(1)),
                              'acceptedAnswer': {'@type': 'Answer', 'text': text(a.group(1))}})
    data = {'@context': 'https://schema.org', '@type': 'FAQPage', 'mainEntity': questions}
    block = ('<!-- faq:schema:start -->\n<script type="application/ld+json">\n'
             + json.dumps(data, ensure_ascii=False, indent=2)
             + '\n</script>\n<!-- faq:schema:end -->')
    return BLOCK.sub(lambda _: block, src), len(questions)


def main():
    done = []
    for base, dirs, files in os.walk(ROOT):
        dirs[:] = [d for d in dirs if d not in ('.git', '.github', 'tools', 'data', 'assets')]
        if 'index.html' not in files:
            continue
        path = os.path.join(base, 'index.html')
        before = open(path, encoding='utf-8').read()
        if not BLOCK.search(before):
            continue
        src, count = schema(before)
        if src != before:
            open(path, 'w', encoding='utf-8', newline='\n').write(src)
        done.append(f'{os.path.relpath(path, ROOT).replace(os.sep, "/")} ({count})')
    print('faq schema: ' + (', '.join(done) if done else 'no page asks for one'))


if __name__ == '__main__':
    main()
