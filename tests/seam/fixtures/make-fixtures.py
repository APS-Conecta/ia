# SPDX-License-Identifier: AGPL-3.0-or-later
"""The binary seam fixtures, rebuilt from this file: python3 tests/seam/fixtures/make-fixtures.py

One short CESFAM protocol, with a contradiction in it, as a PDF with a text layer, a DOCX and an ODT;
a twelve-page PDF; a scanned PDF (an image, no text); a password-protected PDF (pypdf); and a damaged
PDF. The PDFs, DOCX and ODT are written by hand so that nothing but pypdf is needed.
"""
import io
import zipfile
from pathlib import Path
from xml.sax.saxutils import escape

HERE = Path(__file__).resolve().parent

SECTIONS = [
    ("Objetivo", ["Estandarizar la curación de heridas crónicas en usuarios del CESFAM Los Aromos,",
                  "para disminuir el tiempo de cicatrización y las infecciones."]),
    ("Alcance", ["Aplica a enfermeras y técnicos en enfermería que realizan curaciones en la sala",
                 "de procedimientos o en visita domiciliaria."]),
    ("Procedimiento", ["Lavarse las manos y explicar el procedimiento al usuario.",
                       "Retirar el apósito anterior y valorar la herida con la escala vigente.",
                       "Irrigar la herida con suero fisiológico tibio y secar la piel alrededor.",
                       "Aplicar el apósito primario y cubrir con el apósito secundario."]),
    ("Frecuencia de curación", ["Las heridas infectadas se curan cada 7 días o según indicación.",
                                "Las heridas infectadas deben curarse a diario o cada 48 horas como máximo."]),
    ("Registro", ["La enfermera registra cada curación en la ficha clínica.",
                  "Se hace lo que corresponde con los casos especiales."]),
]


def pdf_string(text):
    raw = text.encode("cp1252")
    return b"(" + raw.replace(b"\\", b"\\\\").replace(b"(", b"\\(").replace(b")", b"\\)") + b")"


def pdf(pages, image=False):
    """A PDF of `pages`, each a list of lines in Helvetica; with image=True, one page holding only an image."""
    objects = [b"<< /Type /Catalog /Pages 2 0 R >>", None,
               b"<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>"]
    kids = []
    if image:
        pixels = bytes((x * 31 + y * 17) % 256 for y in range(64) for x in range(64))
        objects.append(b"<< /Type /XObject /Subtype /Image /Width 64 /Height 64 /ColorSpace /DeviceGray "
                       b"/BitsPerComponent 8 /Length %d >>\nstream\n" % len(pixels) + pixels + b"\nendstream")
        image_id = len(objects)
        pages = [[]]
    for lines in pages:
        if image:
            stream = b"q 495 0 0 742 50 50 cm /Im1 Do Q"
            resources = b"<< /XObject << /Im1 %d 0 R >> >>" % image_id
        else:
            stream = b"BT /F1 11 Tf 15 TL 50 790 Td " + b" ".join(pdf_string(line) + b" Tj T*" for line in lines) + b" ET"
            resources = b"<< /Font << /F1 3 0 R >> >>"
        objects.append(b"<< /Length %d >>\nstream\n" % len(stream) + stream + b"\nendstream")
        objects.append(b"<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources " + resources
                       + b" /Contents %d 0 R >>" % len(objects))
        kids.append(len(objects))
    objects[1] = b"<< /Type /Pages /Kids [" + b" ".join(b"%d 0 R" % k for k in kids) + b"] /Count %d >>" % len(kids)
    out = io.BytesIO()
    out.write(b"%PDF-1.4\n")
    offsets = []
    for number, body in enumerate(objects, start=1):
        offsets.append(out.tell())
        out.write(b"%d 0 obj\n" % number + body + b"\nendobj\n")
    xref = out.tell()
    out.write(b"xref\n0 %d\n0000000000 65535 f \n" % (len(objects) + 1))
    out.writelines(b"%010d 00000 n \n" % offset for offset in offsets)
    out.write(b"trailer\n<< /Size %d /Root 1 0 R >>\nstartxref\n%d\n%%%%EOF\n" % (len(objects) + 1, xref))
    return out.getvalue()


def protocol_lines():
    lines = ["PROTOCOLO DE CURACIÓN DE HERIDAS", ""]
    for title, body in SECTIONS:
        lines += [title.upper(), *body, ""]
    return lines


def docx():
    w = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"'
    paragraph = lambda text, style=None: (
        "<w:p>" + (f'<w:pPr><w:pStyle w:val="{style}"/></w:pPr>' if style else "")
        + f'<w:r><w:t xml:space="preserve">{escape(text)}</w:t></w:r></w:p>')
    body = paragraph("Protocolo de curación de heridas", "Title")
    for title, lines in SECTIONS:
        body += paragraph(title, "Heading1") + "".join(paragraph(line) for line in lines)
    files = {
        "[Content_Types].xml": '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
        '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
        '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/></Types>',
        "_rels/.rels": '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
        '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/></Relationships>',
        "word/document.xml": f'<?xml version="1.0" encoding="UTF-8"?><w:document {w}><w:body>{body}</w:body></w:document>',
        "docProps/app.xml": '<?xml version="1.0" encoding="UTF-8"?><Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"><Pages>2</Pages></Properties>',
    }
    return zipped(files)


def odt():
    ns = ('xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" '
          'xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0" '
          'xmlns:meta="urn:oasis:names:tc:opendocument:xmlns:meta:1.0"')
    body = '<text:h text:outline-level="1">Protocolo de curación de heridas</text:h>'
    for title, lines in SECTIONS:
        body += f'<text:h text:outline-level="2">{escape(title)}</text:h>' + "".join(f"<text:p>{escape(line)}</text:p>" for line in lines)
    files = {
        "mimetype": "application/vnd.oasis.opendocument.text",
        "META-INF/manifest.xml": '<?xml version="1.0" encoding="UTF-8"?><manifest:manifest xmlns:manifest="urn:oasis:names:tc:opendocument:xmlns:manifest:1.0" manifest:version="1.3">'
        '<manifest:file-entry manifest:full-path="/" manifest:media-type="application/vnd.oasis.opendocument.text"/>'
        '<manifest:file-entry manifest:full-path="content.xml" manifest:media-type="text/xml"/>'
        '<manifest:file-entry manifest:full-path="meta.xml" manifest:media-type="text/xml"/></manifest:manifest>',
        "content.xml": f'<?xml version="1.0" encoding="UTF-8"?><office:document-content {ns} office:version="1.3"><office:body><office:text>{body}</office:text></office:body></office:document-content>',
        "meta.xml": f'<?xml version="1.0" encoding="UTF-8"?><office:document-meta {ns} office:version="1.3"><office:meta><meta:document-statistic meta:page-count="2"/></office:meta></office:document-meta>',
    }
    return zipped(files)


def zipped(files):
    out = io.BytesIO()
    with zipfile.ZipFile(out, "w") as archive:
        for name, content in files.items():
            archive.writestr(name, content, zipfile.ZIP_STORED if name == "mimetype" else zipfile.ZIP_DEFLATED)
    return out.getvalue()


def protected(document):
    from pypdf import PdfReader, PdfWriter
    writer = PdfWriter(clone_from=PdfReader(io.BytesIO(document)))
    writer.encrypt(user_password="clave-de-prueba", owner_password="clave-de-prueba", algorithm="RC4-128")
    out = io.BytesIO()
    writer.write(out)
    return out.getvalue()


if __name__ == "__main__":
    lines = protocol_lines()
    protocol = pdf([lines[: len(lines) // 2], lines[len(lines) // 2:]])
    twelve = [[f"Anexo {n}: hoja de registro de curaciones del sector {n}.", "Fecha, usuario, valoración y apósito usado."] for n in range(1, 13)]
    fixtures = {
        "protocolo.pdf": protocol,
        "protocolo-12-paginas.pdf": pdf(twelve),
        "escaneado.pdf": pdf([], image=True),
        "protegido.pdf": protected(protocol),
        "danado.pdf": protocol[: len(protocol) // 3],
        "protocolo.docx": docx(),
        "protocolo.odt": odt(),
    }
    for name, content in fixtures.items():
        (HERE / name).write_bytes(content)
        print(f"{name}: {len(content)} bytes")
