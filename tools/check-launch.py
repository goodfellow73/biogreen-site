#!/usr/bin/env python3
"""Check the launch rules: every old address, the 410s, one domain, the
404 page, robots/sitemap, canonical tags and cookies.

    python tools/check-launch.py                       # offline: plays .htaccess
    python tools/check-launch.py https://www.bio-green.co.il   # the live host

Offline, the RewriteRules in .htaccess are run (in order, Python regex) for
every row of data/redirects-old-site.csv: a rule that does not compile, a row
that no rule catches or that lands somewhere else is reported.

Live, real requests go to the host (redirects not followed). Run it right
after the DNS switch, and again a day later.
"""

import csv
import http.client
import os
import re
import sys
from urllib.parse import quote, unquote, urlparse

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
TABLE = os.path.join(ROOT, 'data', 'redirects-old-site.csv')
bad = 0


def report(ok, what, detail=''):
    global bad
    bad += not ok
    print(f'  {"OK " if ok else "BAD"} {what}{"  — " + detail if detail and not ok else ""}')


def table():
    with open(TABLE, encoding='utf-8-sig', newline='') as f:
        return [(r['old_url'].strip(), r['new_url'].strip()) for r in csv.DictReader(f)
                if r['old_url'].strip().startswith('http')]


def offline():
    rules = []
    for line in open(os.path.join(ROOT, '.htaccess'), encoding='utf-8'):
        m = re.match(r'RewriteRule (\S+) (\S+) \[([^\]]+)\]', line.strip())
        if m and m.group(1) != '^':
            try:
                rules.append((re.compile(m.group(1)), m.group(2), m.group(3)))
            except re.error as e:
                report(False, f'rule compiles: {m.group(1)}', str(e))

    def run(path):
        p = unquote(path).lstrip('/')
        for rx, target, flags in rules:
            if rx.search(p):
                code = re.search(r'R=(\d+)', flags)
                return (410 if 'G' in flags.split(',') else int(code.group(1)) if code else 200), target.rstrip('?')
        return 200, None

    print('old addresses (offline, .htaccess played in Python)')
    for old, new in table():
        path = urlparse(old).path
        for variant in (path, path.rstrip('/')):
            code, to = run(variant)
            report(code == 301 and to == new, f'{unquote(variant)} -> {new}', f'got {code} {to}')
    for path, want in (('/shop/some-old-product/', '/products/'), ('/product/x/', '/products/'),
                       ('/product-category/y/', '/products/')):
        code, to = run(path)
        report(code == 301 and to == want, f'catch-all {path}', f'got {code} {to}')
    code, _ = run('/css/goods.php')
    report(code == 410, '/css/goods.php -> 410', f'got {code}')
    for path in ('/products/', '/privacy/', '/knowledge/detox-patches/', '/'):
        code, to = run(path)
        report(code == 200, f'new page untouched: {path}', f'got {code} {to}')
    for path in ('/tools/cms.py', '/data/content.json', '/.git/config'):
        code, _ = run(path)
        report(code == 404, f'not served: {path}', f'got {code}')


def get(base, path, host=None):
    u = urlparse(base)
    conn = (http.client.HTTPSConnection if u.scheme == 'https' else http.client.HTTPConnection)(
        u.hostname, u.port, timeout=20)
    conn.request('GET', quote(path, safe='/?=&%'), headers={'Host': host or u.hostname,
                                                           'User-Agent': 'biogreen-launch-check'})
    r = conn.getresponse()
    body = r.read(200000).decode('utf-8', 'replace')
    return r.status, r.getheader('Location') or '', r.getheaders(), body


def live(base):
    base = base.rstrip('/')
    origin = urlparse(base)
    print(f'live checks against {base}')
    for old, new in table():
        path = urlparse(old).path
        code, loc, _, _ = get(base, unquote(path))
        to = urlparse(loc).path if loc else ''
        report(code == 301 and to == new, f'{unquote(path)} -> {new}', f'got {code} {loc}')
    code, _, _, _ = get(base, '/css/goods.php?name=vsitemap8v')
    report(code == 410, '/css/goods.php?name=… -> 410', f'got {code}')
    code, _, _, body = get(base, '/no-such-page-xyz/')
    report(code == 404 and 'העמוד לא נמצא' in body, 'missing page: 404 with the designed page', f'got {code}')
    for path in ('/robots.txt', '/sitemap.xml'):
        code, _, _, body = get(base, path)
        report(code == 200 and 'bio-green.co.il' in body, path, f'got {code}')
    for path in ('/tools/cms.py', '/data/content.json', '/.git/config'):
        code, _, _, _ = get(base, path)
        report(code == 404, f'not served: {path}', f'got {code}')
    if origin.hostname == 'www.bio-green.co.il':
        for url in ('http://www.bio-green.co.il/about/', 'http://bio-green.co.il/about/', 'https://bio-green.co.il/about/'):
            code, loc, _, _ = get(url, '/about/')
            report(code == 301 and loc == 'https://www.bio-green.co.il/about/', f'one address: {url}', f'got {code} {loc}')
    for path in ('/', '/products/', '/privacy/'):
        code, _, headers, body = get(base, path)
        cookies = [v for k, v in headers if k.lower() == 'set-cookie']
        report(code == 200, f'{path} 200', f'got {code}')
        report(not cookies, f'{path} sets no cookie (privacy policy 8.1)', '; '.join(cookies))
        report('<link rel="canonical" href="https://www.bio-green.co.il' in body, f'{path} canonical')


if __name__ == '__main__':
    if len(sys.argv) > 1:
        live(sys.argv[1])
    else:
        offline()
    print('\nALL PASSED' if not bad else f'\n{bad} FAILED')
    sys.exit(1 if bad else 0)
