# Owned diff

## dep: smalot/pdfparser — 2026-08-26, ALEPH-010

SEAM: borrowed — serviced by the smalot/pdfparser community; transitive: 0 new packages beyond the parser because its only package dependency is already installed

PAYS WHEN: Aleph must extract embedded text from preserved PDF bytes across compressed streams, font encodings, and PDF object layouts that are not safe to approximate with a small in-house parser

CHARGES WHEN: limited-maintenance releases require compatibility work, malformed PDFs expose parser defects, or replacement requires rewriting the single PDF extractor call site

TRIGGER: ALEPH-010 requires embedded PDF text extraction now; PHP and Laravel provide no PDF text parser

Signals: v2.12.5 released 2026-04-21; 76 historical committers; 198 open issues and 7 open pull requests; the maintainers explicitly describe the project as under limited maintenance
