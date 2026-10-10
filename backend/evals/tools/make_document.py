#!/usr/bin/env python3
"""Build an evaluation document from a plain-text source, as PDF or DOCX (standard library only).

Usage: evals/tools/make_document.py SOURCE.txt OUTPUT.pdf|OUTPUT.docx

Source format: paragraphs separated by blank lines; a line starting with "# " is a heading;
other line breaks inside a paragraph are kept. PDFs wrap lines at spaces (never inside a word),
so extracted text reads like a normal PDF's.
"""
import re
import sys
import textwrap
import zipfile
import zlib
from xml.sax.saxutils import escape


def paragraphs(source):
    blocks = [b.strip('\n') for b in re.split(r'\n\s*\n', open(source, encoding='utf-8').read()) if b.strip()]
    return [(b.startswith('# '), b[2:] if b.startswith('# ') else b) for b in blocks]


def pdf(blocks, out, title):
    width, height, margin, leading = 595, 842, 56, 14
    pages, ops, y = [], [], height - margin

    def new_page():
        nonlocal ops, y
        if ops:
            pages.append(ops)
        ops, y = [], height - margin

    def esc(s):
        return s.replace('\\', '\\\\').replace('(', '\\(').replace(')', '\\)')

    for i, (heading, text) in enumerate(blocks):
        font, size, wrap = ('F2', 13 if i == 0 else 11, 80) if heading else ('F1', 11, 88)
        lines = [w for line in text.split('\n') for w in (textwrap.wrap(line, wrap) or [''])]
        if heading and y - leading * 3 < margin:
            new_page()
        for line in lines:
            if y < margin:
                new_page()
            ops.append(f'BT /{font} {size} Tf {margin} {y} Td ({esc(line)}) Tj ET')
            y -= leading
        y -= leading * 0.6
    new_page()

    objects = ['<< /Type /Catalog /Pages 2 0 R >>', None,
               '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
               '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>']
    kids = []
    for page_ops in pages:
        content = zlib.compress('\n'.join(page_ops).encode('cp1252'))
        objects.append(b'<< /Length %d /Filter /FlateDecode >>\nstream\n' % len(content) + content + b'\nendstream')
        objects.append(f'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {width} {height}] '
                       f'/Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents {len(objects)} 0 R >>')
        kids.append(f'{len(objects)} 0 R')
    objects[1] = f'<< /Type /Pages /Kids [{" ".join(kids)}] /Count {len(kids)} >>'

    data, offsets = bytearray(b'%PDF-1.4\n'), []
    for n, body in enumerate(objects, 1):
        offsets.append(len(data))
        data += b'%d 0 obj\n' % n + (body if isinstance(body, bytes) else body.encode()) + b'\nendobj\n'
    xref = len(data)
    data += b'xref\n0 %d\n0000000000 65535 f \n' % (len(objects) + 1) + b''.join(b'%010d 00000 n \n' % o for o in offsets)
    info = title.encode('cp1252').replace(b'(', b'\\(').replace(b')', b'\\)')
    data += b'trailer\n<< /Size %d /Root 1 0 R /Info << /Title (%s) >> >>\nstartxref\n%d\n%%%%EOF\n' % (len(objects) + 1, info, xref)
    open(out, 'wb').write(data)
    return len(pages)


def docx(blocks, out, title):
    def run(text, bold):
        props = '<w:rPr><w:b/><w:sz w:val="28"/></w:rPr>' if bold else ''
        return f'<w:r>{props}<w:t xml:space="preserve">{escape(text)}</w:t></w:r>'

    body = ''.join('<w:p>' + '<w:r><w:br/></w:r>'.join(run(line, heading) for line in text.split('\n')) + '</w:p>'
                   for heading, text in blocks)
    with zipfile.ZipFile(out, 'w', zipfile.ZIP_DEFLATED) as z:
        z.writestr('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                   '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                   '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                   '<Default Extension="xml" ContentType="application/xml"/>'
                   '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
                   '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
                   '</Types>')
        z.writestr('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                   '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                   '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
                   '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
                   '</Relationships>')
        z.writestr('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                   '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" '
                   f'xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:title>{escape(title)}</dc:title></cp:coreProperties>')
        z.writestr('word/document.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                   '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
                   f'<w:body>{body}</w:body></w:document>')


if __name__ == '__main__':
    if len(sys.argv) != 3 or not sys.argv[2].endswith(('.pdf', '.docx')):
        sys.exit(__doc__)
    source, out = sys.argv[1], sys.argv[2]
    blocks = paragraphs(source)
    title = blocks[0][1].split('\n')[0]
    if out.endswith('.pdf'):
        print(f'{out}: {pdf(blocks, out, title)} page(s)')
    else:
        docx(blocks, out, title)
        print(f'{out}: {len(blocks)} paragraphs')
