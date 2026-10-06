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

_hashes = {}


def stamp_for(abs_path):
    """Eight hex characters of the file's content hash.

    Line endings are normalised first. Git hands a Windows checkout CRLF and a
    Linux one LF, so hashing the bytes on disk gives a different answer here
    than in CI — and the two would then rewrite each other's stamps on every
    push, forever. What is hashed is the file as the repository stores it.
    """
    if abs_path not in _hashes:
        with open(abs_path, 'rb') as f:
            content = f.read().replace(b'\r\n', b'\n')
        _hashes[abs_path] = hashlib.sha256(content).hexdigest()[:8]
    return _hashes[abs_path]


def main():
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

    if changed:
        print(f'{len(changed)} page(s) restamped:')
        for c in changed:
            print('  ' + c)
    else:
        print('all asset links already carry the current stamp.')


if __name__ == '__main__':
    main()
