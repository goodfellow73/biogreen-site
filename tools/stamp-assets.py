#!/usr/bin/env python3
"""Stamp a content hash onto every stylesheet and script link.

    python tools/stamp-assets.py

Without this, a visitor who has the site cached keeps the old CSS after a
deploy until the cache expires, and a change that did ship looks like a change
that did not. The host sends `Cache-Control: max-age=600`, so the window is
short but it lands exactly where it hurts: someone opens the page right after
being told it is updated.

Rewriting the link to `css/site.css?v=<hash of the file>` makes the URL change
whenever the file does, so the browser fetches the new one immediately and
keeps caching the old one for as long as it likes. The hash is of the content,
not the time, so an unchanged file keeps its stamp and this does not produce a
commit on every run.

Run it last — after sync-shell and the generators, which rewrite whole regions
of these files.
"""

import hashlib
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

# href="…/site.css" or src="…/site.js", with any ?v= already on it.
LINK = re.compile(r'''(?P<attr>href|src)="(?P<path>[^"?]+\.(?:css|js))(?:\?v=[^"]*)?"''')

# @import url("colors.css"), with any ?v= already on it.
IMPORT = re.compile(r'''(?P<head>@import\s+(?:url\(\s*)?["'])(?P<path>[^"'?]+)(?:\?v=[^"']*)?(?P<tail>["'])''')

_hashes = {}
_source = {}


def _read(abs_path):
    """The file as the repository stores it, with any stamps we added removed.

    Two normalisations, both so the hash answers the same everywhere. Git hands
    a Windows checkout CRLF and a Linux one LF, and stamps we wrote on a
    previous run are our own output, not content — leaving either in would make
    the hash depend on where and when it ran, and local runs and CI would
    rewrite each other's stamps on every push, forever.
    """
    if abs_path not in _source:
        with open(abs_path, 'rb') as f:
            content = f.read().replace(b'\r\n', b'\n')
        if abs_path.endswith('.css'):
            text = IMPORT.sub(r'\g<head>\g<path>\g<tail>',
                              content.decode('utf-8'))
            content = text.encode('utf-8')
        _source[abs_path] = content
    return _source[abs_path]


def imports_of(abs_path):
    """Local stylesheets this one pulls in. A remote one is not ours to stamp."""
    if not abs_path.endswith('.css'):
        return []
    out = []
    for m in IMPORT.finditer(_read(abs_path).decode('utf-8')):
        rel = m.group('path')
        if '//' in rel:
            continue
        target = os.path.normpath(os.path.join(os.path.dirname(abs_path), rel))
        if not os.path.exists(target):
            sys.exit(f'stamp-assets: {os.path.relpath(abs_path, ROOT)} '
                     f'imports {rel}, which does not exist')
        out.append((rel, target))
    return out


def _bytes_of(abs_path, seen):
    """The file, followed by everything it @imports, depth first.

    An entry point that only pulls in others would otherwise never change its
    own hash, so editing components.css would move nothing. What a page loads
    is the whole tree, so the whole tree is what is hashed.
    """
    if abs_path in seen:
        return b''                      # an import cycle, not a reason to hang
    seen.add(abs_path)
    return b''.join([_read(abs_path)]
                    + [_bytes_of(t, seen) for _, t in imports_of(abs_path)])


def stamp_for(abs_path):
    """Eight hex characters covering the file and anything it imports."""
    if abs_path not in _hashes:
        _hashes[abs_path] = hashlib.sha256(_bytes_of(abs_path, set())).hexdigest()[:8]
    return _hashes[abs_path]


def stamp_imports():
    """Put a stamp on the @import URLs inside the stylesheets themselves.

    Stamping only the <link> makes the browser re-fetch the entry point, but
    every file it imports is requested at an unchanged URL and comes straight
    back out of the cache — so a change to components.css still would not
    reach anyone. The import URLs have to carry their own version.

    Returns the files it rewrote.
    """
    changed = []
    pending, seen = [os.path.join(ROOT, 'css', 'design-system.css')], set()

    while pending:
        css = pending.pop()
        if css in seen or not os.path.exists(css):
            continue
        seen.add(css)

        children = imports_of(css)
        pending += [t for _, t in children]
        if not children:
            continue

        def restamp(m, base=os.path.dirname(css)):
            target = os.path.normpath(os.path.join(base, m.group('path')))
            return (f'{m.group("head")}{m.group("path")}'
                    f'?v={stamp_for(target)}{m.group("tail")}')

        with open(css, encoding='utf-8') as f:
            before = f.read()
        after = IMPORT.sub(restamp, before)
        if after != before:
            with open(css, 'w', encoding='utf-8', newline='\n') as f:
                f.write(after)
            changed.append(os.path.relpath(css, ROOT).replace(os.sep, '/'))

    return changed


def main():
    restamped_css = stamp_imports()

    pages = []
    for base, dirs, files in os.walk(ROOT):
        dirs[:] = [d for d in dirs if d not in ('.git', '.github', 'tools', 'data')]
        pages += [os.path.join(base, f) for f in files if f.endswith('.html')]

    changed, missing = [], []

    for page in sorted(pages):
        with open(page, encoding='utf-8') as f:
            html = f.read()

        def replace(m):
            rel = m.group('path')
            target = os.path.normpath(os.path.join(os.path.dirname(page), rel))
            if not os.path.exists(target):
                missing.append(f'{os.path.relpath(page, ROOT)} -> {rel}')
                return m.group(0)
            return f'{m.group("attr")}="{rel}?v={stamp_for(target)}"'

        out = LINK.sub(replace, html)
        if out != html:
            with open(page, 'w', encoding='utf-8', newline='\n') as f:
                f.write(out)
            changed.append(os.path.relpath(page, ROOT).replace(os.sep, '/'))

    # A link pointing at nothing is a broken page, not a stamping problem —
    # say so loudly rather than quietly leaving it unstamped.
    if missing:
        sys.exit('stamp-assets: these links point at files that do not exist:\n  '
                 + '\n  '.join(missing))

    if changed or restamped_css:
        for c in restamped_css:
            print(f'  {c} (import URLs)')
        for c in changed:
            print('  ' + c)
        print(f'{len(changed) + len(restamped_css)} file(s) restamped.')
    else:
        print('all asset links already carry the current stamp.')


if __name__ == '__main__':
    main()
