# Owned diff

## dep: smalot/pdfparser — 2026-08-26, ALEPH-010

SEAM: borrowed — serviced by the smalot/pdfparser community; transitive: 0 new packages beyond the parser because its only package dependency is already installed

PAYS WHEN: Aleph must extract embedded text from preserved PDF bytes across compressed streams, font encodings, and PDF object layouts that are not safe to approximate with a small in-house parser

CHARGES WHEN: limited-maintenance releases require compatibility work, malformed PDFs expose parser defects, or replacement requires rewriting the single PDF extractor call site

TRIGGER: ALEPH-010 requires embedded PDF text extraction now; PHP and Laravel provide no PDF text parser

Signals: v2.12.5 released 2026-04-21; 76 historical committers; 198 open issues and 7 open pull requests; the maintainers explicitly describe the project as under limited maintenance

## dep: symfony/uid — 2026-08-28, MME-1568

SEAM: borrowed — ULID generation, serviced by Symfony; transitive: 1 new package, and zero in any host that already has laravel/framework.

PAYS WHEN: `Acceptance\Submissions` calls `Str::ulid()`, which it does for every submission.

CHARGES WHEN: never seriously.

TRIGGER: fired now — Aleph was relying on `laravel/framework` to pull this in. In a host built on the split `illuminate/*` packages (Laravel Zero, which Stacks is) it is absent, and the first acceptance fails with `Class "Symfony\Component\Uid\Ulid" not found`. Found by running Aleph inside Stacks; a package that calls it should require it.

## dep: dragonmantank/cron-expression — 2026-08-28, MME-801

SEAM: borrowed — standard cron validation and timezone-aware next-run calculation; zero new transitive packages because Illuminate Console already installs it

PAYS WHEN: every schedule create, edit, enable, and successful dispatch calculates the next due instant from the stored cron expression and timezone

CHARGES WHEN: cron grammar or daylight-saving behavior changes upstream, or removal requires replacing one schedule calculation call site

TRIGGER: MME-801 requires arbitrary per-installation recurring cadence now; Aleph directly calls the parser and therefore declares it instead of relying on Laravel's transitive dependency
