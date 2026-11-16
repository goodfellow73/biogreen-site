#!/usr/bin/env python3
"""The import guide PDF, tagged for screen readers, from its HTML source.

Run from the site root, by hand, after editing tools/guide-pdf/guide.html:
    python tools/build-guide-pdf.py

Writes assets/docs/biogreen-import-guide.pdf (the address the site and the
Make e-mail link to). Headless Chrome prints it with --export-tagged-pdf, so
the PDF carries its structure (headings, lists, paragraphs, the logo's alt),
the document language from <html lang="he"> and the title from <title>.
Chrome writes the bookmarks of Hebrew headings with their words in reverse
order, so they are rewritten here from the headings themselves.

Needs Google Chrome and PyMuPDF (pip install pymupdf). Not part of the build.
"""

import html
import os
import re
import shutil
import subprocess
import sys
import tempfile

import pymupdf

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SRC = os.path.join(ROOT, 'tools', 'guide-pdf', 'guide.html')
OUT = os.path.join(ROOT, 'assets', 'docs', 'biogreen-import-guide.pdf')
CHROME = [r'C:\Program Files\Google\Chrome\Application\chrome.exe',
          '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
          'google-chrome', 'chromium']


def chrome():
    for c in CHROME:
        if os.path.exists(c) or shutil.which(c):
            return c
    sys.exit('build-guide-pdf: Google Chrome not found')


def main():
    tmp = tempfile.mkdtemp()
    raw = os.path.join(tmp, 'guide.pdf')
    subprocess.run([chrome(), '--headless=new', '--disable-gpu', '--no-pdf-header-footer',
                    '--export-tagged-pdf', '--generate-pdf-document-outline',
                    '--virtual-time-budget=8000', f'--user-data-dir={os.path.join(tmp, "profile")}',
                    f'--print-to-pdf={raw}', 'file:///' + SRC.replace(os.sep, '/')],
                   check=True, capture_output=True)

    doc = pymupdf.open(raw)
    cat = doc.xref_object(doc.pdf_catalog())
    if 'StructTreeRoot' not in cat or doc.xref_get_key(doc.pdf_catalog(), 'Lang')[1] != 'he':
        sys.exit('build-guide-pdf: the PDF came out untagged or without lang="he"')

    src = open(SRC, encoding='utf-8').read()
    heads = [html.unescape(re.sub(r'<[^>]+>', '', m.group(2))).strip()
             for m in re.finditer(r'<h([1-6])[^>]*>(.*?)</h\1>', src, re.S)]
    toc = doc.get_toc(simple=False)
    if len(toc) == len(heads):
        doc.set_toc([[e[0], h, e[2], e[3]] for e, h in zip(toc, heads)])
    else:
        print(f'note: {len(toc)} bookmarks for {len(heads)} headings; bookmarks left as Chrome wrote them')

    doc.save(OUT, garbage=3, deflate=True)
    shutil.rmtree(tmp, ignore_errors=True)
    print(f'{os.path.relpath(OUT, ROOT)}: {doc.page_count} pages, tagged, lang he')


if __name__ == '__main__':
    main()
