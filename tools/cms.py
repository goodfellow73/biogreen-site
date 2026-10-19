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
"""

import html
import json
import os
import re
import sys

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


# ---------------------------------------------------------------- fields

def read_field(open_tag, inner):
    kind = attr(open_tag, 'data-cms-type') or 'text'
    target = attr(open_tag, 'data-cms-attr')
    if target:
        return re.sub(r'\s+', ' ', attr(open_tag, target) or '').strip()
    if kind in ('text', 'long'):
        return plain(inner)
    if kind == 'para':
        return para_value(inner)
    items = [inner[a:b] for a, b in children(inner)]
    rows = []
    for it in items:
        spans = slot_spans(it)
        get = lambda k: para_value(it[spans[k][0]:spans[k][1]]) if k in spans else ''
        rows.append(get('1') if kind == 'lines' else (get('1') + '\n' + get('2')).strip())
    return ('\n' if kind == 'lines' else '\n\n').join(rows)


def write_field(open_tag, inner, value):
    """New (open_tag, inner) for the element, with `value` written in."""
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
            lines = [l.strip() for l in chunk.split('\n') if l.strip()]
            if lines:
                rows.append({'1': lines[0], '2': '\n'.join(lines[1:])})
    if not rows:
        return open_tag, inner          # an emptied list is a mistake, not an instruction

    fixed = has_attr(open_tag, 'data-cms-fixed')
    if fixed:
        rows = rows[:len(items)] + [None] * (len(items) - len(rows))
    out = []
    for i, row in enumerate(rows):
        if i < len(items):
            out.append(items[i] if row is None else fill_slots(items[i], row))
        else:
            out.append(renumber(fill_slots(items[-1], row), i + 1))

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


def extract():
    doc = {'pages': []}
    for page_id, page_label, path in pages():
        src = open(path, encoding='utf-8').read()
        labels = section_labels(src)
        sections = {}
        for key, label, kind, fixed, _, open_tag, inner in fields(src):
            sec = key.split('.', 1)[0]
            field = {'key': key, 'label': label, 'type': kind,
                     'value': read_field(open_tag, inner), 'help': TYPE_HELP.get(kind, '')}
            if fixed:
                count = len(children(inner))
                field['fixed'] = count
                field['help'] = (field['help'] + f' מספר הפריטים קבוע: {count}.').strip()
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
            new_open, new_inner = write_field(open_tag, inner, value)
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
