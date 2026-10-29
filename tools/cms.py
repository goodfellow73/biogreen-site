#!/usr/bin/env python3
"""Page texts edited in WordPress.

    python tools/cms.py extract   # HTML  -> data/cms-schema.json
    python tools/cms.py apply     # data/content.json -> HTML

The HTML stays the template. Any element carrying data-cms="section.name" is
an editable text; the WordPress plugin reads the field list this script
writes, shows the client one field per text, and the build writes her
wording back into the same elements. Design, structure and everything else
stay in code.

Marking an element
------------------
  data-cms="hero.title"         key; the part before the dot is the section
  data-cms-label="כותרת ראשית"  what the client sees beside the field
  data-cms-type="…"             text | long | para | lines | pairs (default text)
  data-cms-attr="content"       write into this attribute, not the element
  data-cms-fixed                lists only: the number of items cannot change
  data-cms-keyed                lists only: items follow their title, not their
                                place (see below)
  data-cms-item-labels="מונח|הסבר"
                                pairs only: what the editor calls the two parts
  data-cms-item-only            inside a list item: belongs to that item alone,
                                left out of a copy made for a new item
  data-cms-link                 inside a pairs item: the item's own link (see
                                "Links" below)
  data-cms-anchor               on an element with an id: offered as a link
                                target in the editor, named by its text

  <section data-cms-section="hero" data-cms-section-label="באנר ראשי">
                                names a section in the editor
  <body data-cms-page="home" data-page-label="דף הבית">
                                makes the page editable at all

Types
-----
  text   one line
  long   one paragraph, written as typed (for attributes such as a meta
         description)
  para   paragraphs; a new line becomes <br>, and [[words]] become the
         element's existing link — so a link that opens a pop-up survives
         editing without the client ever seeing its markup
  lines  a list, one item per line, filling data-cms-slot="1" of each item
  pairs  a list of title + text, blank line between items, filling slots 1
         and 2 — cards, FAQ

Lists are updated in place: the first items keep their own markup (each card
its own icon, the first FAQ answer open), only the words change. An added
item is a copy of the last one; a removed item is dropped. data-cms-fixed
stops both, for lists whose layout depends on the count.

By default an item is matched by its place in the list. A keyed list matches
by title instead, then by text (a renamed item keeps its text), then by
place, so an item's own markup — its anchor id, a link that belongs to it —
stays with it when others are removed or reordered. A new item in a keyed
list gets empty ids; the page's own build step names them (the glossary's
anchors come from tools/build-glossary.py).

Links
-----
A pairs list that holds <template data-cms-link-template> (its first child)
gives every item an optional link: the element marked data-cms-link inside
the item, made from the template when the item has none. In the value it is
the item's last line:

    @@ link text | /import-regulation/#importer

URLs are kept from the site root, the way the editor picks them; the build
writes them relative to the page (../../import-regulation/#importer), since
the site may sit under a sub-path. extract also lists every page and its
named sections ("links" in the schema), for the editor's link picker.
"""

import html
import json
import os
import re
import sys
from urllib.parse import urljoin

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SCHEMA = os.path.join(ROOT, 'data', 'cms-schema.json')
CONTENT = os.path.join(ROOT, 'data', 'content.json')

VOID = {'meta', 'input', 'img', 'br', 'link', 'hr', 'source'}
SECTION_FALLBACK = {'seo': 'תוצאות חיפוש בגוגל (SEO)'}
TYPE_HELP = {
    'text': '',
    'long': '',
    'para': 'שורה חדשה = ירידת שורה. טקסט בתוך [[ ]] הוא הקישור.',
    'lines': 'פריט בכל שורה.',
    'pairs': 'כותרת בשורה הראשונה והטקסט אחריה. שורה ריקה בין פריט לפריט.',
}

MARK = re.compile(r'<([a-zA-Z][a-zA-Z0-9]*)\b[^>]*?\sdata-cms="([^"]+)"[^>]*>')


# ------------------------------------------------------------- html bits

def attr(tag, name):
    m = re.search(r'\s%s="([^"]*)"' % re.escape(name), tag)
    return html.unescape(m.group(1)) if m else None


def has_attr(tag, name):
    return re.search(r'\s%s(?:[\s>=/]|$)' % re.escape(name), tag) is not None


def element_end(src, start):
    """Index just past the element whose opening tag starts at `start`."""
    m = re.match(r'<([a-zA-Z][a-zA-Z0-9]*)\b[^>]*>', src[start:])
    name = m.group(1).lower()
    pos = start + m.end()
    if name in VOID or m.group(0).endswith('/>'):
        return pos
    depth = 1
    tag = re.compile(r'<(/?)%s\b[^>]*>' % name, re.I)
    while depth:
        t = tag.search(src, pos)
        if not t:
            raise ValueError(f'unclosed <{name}> at {start}')
        depth += -1 if t.group(1) else 1
        pos = t.end()
    return pos


def split_element(src, start):
    """(open_tag, inner, close_tag_start, end) for the element at `start`."""
    end = element_end(src, start)
    open_end = src.index('>', start) + 1
    close_start = src.rindex('</', open_end, end) if end > open_end else end
    return src[start:open_end], src[open_end:close_start], close_start, end


def children(inner):
    """Spans of the top-level elements inside `inner`, comments skipped."""
    out, pos = [], 0
    while True:
        m = re.compile(r'<!--.*?-->|<[a-zA-Z]', re.S).search(inner, pos)
        if not m:
            return out
        if m.group(0).startswith('<!--'):
            pos = m.end()
            continue
        end = element_end(inner, m.start())
        # A <template> is markup kept for later, not an item.
        if not inner.startswith('<template', m.start()):
            out.append((m.start(), end))
        pos = end


def plain(fragment):
    text = re.sub(r'<[^>]+>', '', fragment)
    return re.sub(r'\s+', ' ', html.unescape(text)).strip()


def para_value(fragment):
    """Inner HTML -> the text the client edits: <br> as a new line, links
    as [[words]], every other tag dropped."""
    # Only <br> is a line break. A line break in the source is just where the
    # code was wrapped — the browser shows it as a space — so it is collapsed
    # first; otherwise the field would show a break the page does not have,
    # and saving it unchanged would turn it into a real one.
    s = re.sub(r'\s+', ' ', fragment)
    s = re.sub(r'\s*<br\s*/?>\s*', '\n', s, flags=re.I)
    s = re.sub(r'<a\b[^>]*>(.*?)</a>', lambda m: '[[' + plain(m.group(1)) + ']]', s, flags=re.S)
    s = html.unescape(re.sub(r'<[^>]+>', '', s))
    return '\n'.join(l.strip() for l in s.split('\n')).strip()


def para_html(value, link_template=None):
    """The client's text -> inner HTML."""
    lines = [l.strip() for l in value.replace('\r\n', '\n').split('\n')]
    out = []
    for line in lines:
        parts = re.split(r'\[\[(.+?)\]\]', line)
        buf = []
        for i, part in enumerate(parts):
            if i % 2 == 0:
                buf.append(html.escape(part, quote=False))
            elif link_template:
                buf.append(link_template[0] + html.escape(part, quote=False) + link_template[1])
            else:
                buf.append(html.escape(part, quote=False))
        out.append(''.join(buf))
    return '<br>'.join(out)


def slot_spans(item):
    """{slot number: (inner_start, inner_end)} within one list item."""
    out = {}
    for m in re.finditer(r'<[a-zA-Z][a-zA-Z0-9]*\b[^>]*\sdata-cms-slot="(\d+)"[^>]*>', item):
        tag, inner, close_start, _ = split_element(item, m.start())
        out[m.group(1)] = (m.start() + len(tag), close_start)
    return out


def fill_slots(item, values):
    for slot in sorted(values, key=int, reverse=False):
        spans = slot_spans(item)
        if slot not in spans:
            continue
        a, b = spans[slot]
        item = item[:a] + para_html(values[slot]) + item[b:]
    return item


def renumber(item, n):
    """A copied item must not reuse the ids of the one it was copied from."""
    return re.sub(r'(\s(?:id|aria-controls|for)=")([^"]*?)(\d+)(")',
                  lambda m: f'{m.group(1)}{m.group(2)}{n}{m.group(4)}', item)


def strip_item_only(item):
    """Drop the parts marked data-cms-item-only, with the line each sat on."""
    while True:
        m = re.search(r'<[a-zA-Z][a-zA-Z0-9]*\b[^>]*\sdata-cms-item-only\b[^>]*>', item)
        if not m:
            return item
        start, end = m.start(), element_end(item, m.start())
        ws = re.search(r'\n[ \t]*$', item[:start])
        item = item[:ws.start() if ws else start] + item[end:]


def slot_text(item, slot):
    spans = slot_spans(item)
    return para_value(item[spans[slot][0]:spans[slot][1]]) if slot in spans else ''


def match_keyed(items, rows):
    """For each row, the index of the item it updates, or None for a new one."""
    old = [(slot_text(it, '1'), slot_text(it, '2')) for it in items]
    claim, used = [None] * len(rows), set()
    for part, slot in ((0, '1'), (1, '2')):
        for j, row in enumerate(rows):
            want = row.get(slot, '')
            if claim[j] is not None or not want:
                continue
            i = next((i for i, o in enumerate(old) if i not in used and o[part] == want), None)
            if i is not None:
                claim[j] = i
                used.add(i)
    for j in range(len(rows)):
        if claim[j] is None and j < len(items) and j not in used:
            claim[j] = j
            used.add(j)
    return claim


# ----------------------------------------------------------------- links

LINK_MARK = '@@'
LINK_EL = re.compile(r'<[a-zA-Z][a-zA-Z0-9]*\b[^>]*\sdata-cms-link\b[^>]*>')
LINK_TEMPLATE = re.compile(r'<template data-cms-link-template>(.*?)</template>', re.S)


def site_url(href, page_dir):
    """A page-relative href -> the same address from the site root."""
    if re.match(r'^(?:[a-z][a-z0-9+.-]*:|/)', href, re.I):
        return href
    full = urljoin('http://site/' + (page_dir + '/' if page_dir else ''), href)
    return full[len('http://site'):]


def page_href(url, page_dir):
    """A URL as typed or picked in the editor -> an href for this page, or
    None when it is not one a link may carry (javascript: and the like).

    Inside this page's folder the href is the short one ("glossary/#coa",
    "#contact-form" for this very page); anywhere else it climbs to the site
    root first. Either way a page saved unchanged comes back unchanged."""
    url = url.strip()
    if re.match(r'^(?:https?://|mailto:|tel:|#)', url, re.I):
        return url
    if re.match(r'^[a-z][a-z0-9+.-]*:', url, re.I):
        return None
    path, hash_, frag = url.lstrip('/').partition('#')
    here = page_dir + '/' if page_dir else ''
    if path.startswith(here):
        rel = path[len(here):]
    else:
        rel = '../' * (page_dir.count('/') + 1) + path
    if not rel:
        return hash_ + frag if frag else './'
    return rel + hash_ + frag


def read_link(item, page_dir):
    """'text | url' for the item's link, or ''."""
    m = LINK_EL.search(item)
    if not m:
        return ''
    a = re.search(r'<a\b[^>]*\shref="([^"]*)"[^>]*>(.*?)</a>', item[m.start():element_end(item, m.start())], re.S)
    if not a:
        return ''
    text = plain(re.sub(r'<svg\b.*?</svg>', '', a.group(2), flags=re.S))
    return f'{text} | {site_url(html.unescape(a.group(1)), page_dir)}'


def set_link(item, link, template, page_dir):
    """Give the item this (text, url) link, or take its link away (None)."""
    m = LINK_EL.search(item)
    href = page_href(link[1], page_dir) if link and link[1] else None
    if not link or not link[0] or href is None:
        if m:
            start, end = m.start(), element_end(item, m.start())
            ws = re.search(r'\n[ \t]*$', item[:start])
            item = item[:ws.start() if ws else start] + item[end:]
        return item
    if m:
        start, end = m.start(), element_end(item, m.start())
        markup = item[start:end]
    elif template:
        # Straight after the last text, on its own line, indented like it.
        slots = [(s.start(), element_end(item, s.start()))
                 for s in re.finditer(r'<[a-zA-Z][a-zA-Z0-9]*\b[^>]*\sdata-cms-slot="\d+"[^>]*>', item)]
        if not slots:
            return item
        s_start, start = max(slots, key=lambda s: s[1])
        end = start
        indent = item[item.rfind('\n', 0, s_start) + 1:s_start]
        markup = '\n' + (indent if not indent.strip() else '') + template.strip()
    else:
        return item
    markup = re.sub(r'(<a\b[^>]*\shref=")[^"]*(")', lambda x: x.group(1) + html.escape(href, quote=True) + x.group(2),
                    markup, count=1)
    markup = re.sub(r'(<a\b[^>]*>)[^<]*', lambda x: x.group(1) + html.escape(link[0], quote=False), markup, count=1)
    return item[:start] + markup + item[end:]


def split_link(lines):
    """Lines of one pairs item -> (lines without the link line, (text, url) or None)."""
    if len(lines) > 1 and lines[-1].startswith(LINK_MARK):
        body = lines[-1][len(LINK_MARK):]
        text, _, url = body.rpartition('|')
        if not _:
            text, url = body, ''
        return lines[:-1], (text.strip(), url.strip())
    return lines, None


def site_links():
    """Every page of the site and its named places, for the link picker."""
    found = []
    for base, dirs, files in os.walk(ROOT):
        dirs[:] = [d for d in dirs if d not in ('.git', '.github', 'tools', 'data', 'assets', 'a11y')]
        if 'index.html' not in files:
            continue
        rel = os.path.relpath(base, ROOT).replace(os.sep, '/')
        url = '/' if rel == '.' else f'/{rel}/'
        src = open(os.path.join(base, 'index.html'), encoding='utf-8').read()
        body = re.search(r'<body\b[^>]*>', src)
        name = attr(body.group(0), 'data-page-label') if body else None
        if not name:
            h1 = re.search(r'<h1\b[^>]*>(.*?)</h1>', src, re.S)
            name = plain(h1.group(1)) if h1 else url
        name = re.sub(r'^עמוד\s+', '', name)
        anchors = []
        for t in re.finditer(r'<[a-zA-Z][a-zA-Z0-9]*\b[^>]*\sid="([^"]+)"[^>]*>', src):
            tag, ident = t.group(0), t.group(1)
            if has_attr(tag, 'data-cms-anchor'):
                label = plain(split_element(src, t.start())[1])
            elif attr(tag, 'data-cms-section-label') and ident != 'hero':
                # "(…)" in a section label is a note for the editor, not a name.
                label = re.sub(r'\s*\(.*?\)', '', attr(tag, 'data-cms-section-label'))
            else:
                continue
            anchors.append({'url': f'{url}#{ident}', 'label': label})
        found.append({'url': url, 'label': name, 'anchors': anchors})
    found.sort(key=lambda p: (p['url'] != '/', p['url']))
    return found


# ---------------------------------------------------------------- fields

def read_field(open_tag, inner, page_dir=''):
    kind = attr(open_tag, 'data-cms-type') or 'text'
    target = attr(open_tag, 'data-cms-attr')
    if target:
        return re.sub(r'\s+', ' ', attr(open_tag, target) or '').strip()
    if kind in ('text', 'long'):
        return plain(inner)
    if kind == 'para':
        return para_value(inner)
    items = [inner[a:b] for a, b in children(inner)]
    links = kind == 'pairs' and LINK_TEMPLATE.search(inner)
    rows = []
    for it in items:
        spans = slot_spans(it)
        get = lambda k: para_value(it[spans[k][0]:spans[k][1]]) if k in spans else ''
        row = get('1') if kind == 'lines' else (get('1') + '\n' + get('2')).strip()
        link = read_link(it, page_dir) if links else ''
        rows.append(row + (f'\n{LINK_MARK} {link}' if link else ''))
    return ('\n' if kind == 'lines' else '\n\n').join(rows)


def write_field(open_tag, inner, value, page_dir=''):
    """New (open_tag, inner) for the element, with `value` written in.
    page_dir is the page's folder from the site root ('' for the home page),
    for the links a pairs list may carry."""
    kind = attr(open_tag, 'data-cms-type') or 'text'
    target = attr(open_tag, 'data-cms-attr')
    if target:
        new = html.escape(re.sub(r'\s+', ' ', value).strip(), quote=True)
        return re.sub(r'(\s%s=")[^"]*(")' % re.escape(target),
                      lambda m: m.group(1) + new + m.group(2), open_tag, count=1), inner
    if kind in ('text', 'long'):
        return open_tag, html.escape(re.sub(r'\s+', ' ', value).strip(), quote=False)
    if kind == 'para':
        link = re.search(r'(<a\b[^>]*>).*?(</a>)', inner, re.S)
        return open_tag, para_html(value, (link.group(1), link.group(2)) if link else None)

    # lists, updated in place
    spans = children(inner)
    if not spans:
        return open_tag, inner
    items = [inner[a:b] for a, b in spans]
    if kind == 'lines':
        rows = [{'1': l.strip()} for l in value.replace('\r\n', '\n').split('\n') if l.strip()]
    else:
        rows = []
        for chunk in re.split(r'\n\s*\n', value.replace('\r\n', '\n').strip()):
            lines, link = split_link([l.strip() for l in chunk.split('\n') if l.strip()])
            if lines:
                rows.append({'1': lines[0], '2': '\n'.join(lines[1:]), 'link': link})
    if not rows:
        return open_tag, inner          # an emptied list is a mistake, not an instruction

    fixed = has_attr(open_tag, 'data-cms-fixed')
    if fixed:
        rows = rows[:len(items)] + [None] * (len(items) - len(rows))
    template = strip_item_only(items[-1])
    link_tpl = LINK_TEMPLATE.search(inner) if kind == 'pairs' else None

    def fill(item, row):
        item = fill_slots(item, {k: row[k] for k in ('1', '2') if k in row})
        if link_tpl:
            item = set_link(item, row.get('link'), link_tpl.group(1), page_dir)
        return item

    out = []
    if has_attr(open_tag, 'data-cms-keyed') and not fixed:
        blank = re.sub(r'(\sid=")[^"]*(")', r'\1\2', template)
        for row, i in zip(rows, match_keyed(items, rows)):
            out.append(fill(blank if i is None else items[i], row))
    else:
        for i, row in enumerate(rows):
            if i < len(items):
                out.append(items[i] if row is None else fill(items[i], row))
            else:
                out.append(renumber(fill(template, row), i + 1))

    lead = inner[:spans[0][0]]
    indent = lead[lead.rfind('\n'):] if '\n' in lead else lead
    tail = inner[spans[-1][1]:]
    tail = tail[tail.rfind('\n'):] if '\n' in tail else tail
    return open_tag, lead + indent.join(out) + tail


# ----------------------------------------------------------------- pages

def pages():
    """Every page whose <body> carries data-cms-page."""
    found = []
    for base, dirs, files in os.walk(ROOT):
        dirs[:] = [d for d in dirs if d not in ('.git', '.github', 'tools', 'data', 'assets')]
        for f in files:
            if f != 'index.html':
                continue
            path = os.path.join(base, f)
            src = open(path, encoding='utf-8').read()
            body = re.search(r'<body\b[^>]*>', src)
            if body and attr(body.group(0), 'data-cms-page'):
                found.append((attr(body.group(0), 'data-cms-page'),
                              attr(body.group(0), 'data-page-label') or '', path))
    return sorted(found)


def fields(src):
    """(key, label, type, fixed, start, open_tag, inner) for each marked element."""
    out = []
    for m in MARK.finditer(src):
        open_tag, inner, _, _ = split_element(src, m.start())
        out.append((m.group(2), attr(open_tag, 'data-cms-label') or m.group(2),
                    attr(open_tag, 'data-cms-type') or 'text',
                    has_attr(open_tag, 'data-cms-fixed'), m.start(), open_tag, inner))
    keys = [f[0] for f in out]
    dup = {k for k in keys if keys.count(k) > 1}
    if dup:
        sys.exit(f'cms: duplicate data-cms keys: {", ".join(sorted(dup))}')
    return out


def section_labels(src):
    labels = dict(SECTION_FALLBACK)
    for m in re.finditer(r'<[a-zA-Z]+\b[^>]*\sdata-cms-section="([^"]+)"[^>]*>', src):
        labels[m.group(1)] = attr(m.group(0), 'data-cms-section-label') or m.group(1)
    return labels


def page_dir(path):
    d = os.path.relpath(os.path.dirname(path), ROOT).replace(os.sep, '/')
    return '' if d == '.' else d


def extract():
    doc = {'pages': [], 'links': site_links()}
    for page_id, page_label, path in pages():
        src = open(path, encoding='utf-8').read()
        labels = section_labels(src)
        sections = {}
        for key, label, kind, fixed, _, open_tag, inner in fields(src):
            sec = key.split('.', 1)[0]
            field = {'key': key, 'label': label, 'type': kind,
                     'value': read_field(open_tag, inner, page_dir(path)), 'help': TYPE_HELP.get(kind, '')}
            if fixed:
                count = len(children(inner))
                field['fixed'] = count
                field['help'] = (field['help'] + f' מספר הפריטים קבוע: {count}.').strip()
            if kind == 'pairs':
                parts = (attr(open_tag, 'data-cms-item-labels') or 'כותרת|טקסט').split('|')
                field['item_labels'] = [p.strip() for p in parts[:2]]
                if LINK_TEMPLATE.search(inner):
                    field['links'] = True
            sections.setdefault(sec, {'id': sec, 'label': labels.get(sec, sec), 'fields': []})
            sections[sec]['fields'].append(field)
        doc['pages'].append({'id': page_id, 'label': page_label,
                             'file': os.path.relpath(path, ROOT).replace(os.sep, '/'),
                             'sections': list(sections.values())})
    with open(SCHEMA, 'w', encoding='utf-8', newline='\n') as f:
        json.dump(doc, f, ensure_ascii=False, indent=2)
        f.write('\n')
    n = sum(len(s['fields']) for p in doc['pages'] for s in p['sections'])
    print(f'cms: {n} editable texts on {len(doc["pages"])} page(s) -> data/cms-schema.json')


def apply():
    if not os.path.exists(CONTENT):
        print('cms: no data/content.json — the HTML is used as it stands.')
        return
    with open(CONTENT, encoding='utf-8') as f:
        content = json.load(f)
    changed = []
    for page_id, _, path in pages():
        values = content.get(page_id) or {}
        if not values:
            continue
        src = open(path, encoding='utf-8').read()
        before = src
        # Back to front, so earlier offsets stay valid as later ones change.
        for key, _, _, _, start, open_tag, inner in reversed(fields(src)):
            if key not in values or values[key] is None:
                continue
            value = str(values[key])
            if not value.strip():
                continue            # an emptied field keeps the page's text
            new_open, new_inner = write_field(open_tag, inner, value, page_dir(path))
            end = element_end(src, start)
            close = src[start + len(open_tag) + len(inner):end]
            src = src[:start] + new_open + new_inner + close + src[end:]
        if src != before:
            open(path, 'w', encoding='utf-8', newline='\n').write(src)
            changed.append(os.path.relpath(path, ROOT).replace(os.sep, '/'))
    print('cms: texts from WordPress written to ' + (', '.join(changed) if changed else 'nothing — already current'))


if __name__ == '__main__':
    cmd = sys.argv[1] if len(sys.argv) > 1 else ''
    if cmd == 'extract':
        extract()
    elif cmd == 'apply':
        apply()
    else:
        sys.exit('usage: python tools/cms.py extract|apply')
