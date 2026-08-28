# Investigation — SchoolMessenger Presence (ALEPH-011)

Everything below rests on requests that were actually issued and actually returned. Nothing here is
inferred from vendor documentation, and no endpoint was constructed from a template. Where a request
was not made, this record says so and says why.

Captured 2026-08-26 with `User-Agent: AlephCrawler/0.1 (+https://github.com/sifrious/aleph)`, GET
unless noted, redirects followed.

## Captured requests

| Requested | Final | Status | Content type | Bytes |
| --- | --- | --- | --- | --- |
| `https://www.ahsd.org/robots.txt` | `https://www.ahsd.org/robots.txt/Default.aspx` | 200 after 3 redirects | `text/plain; charset=utf-8` | 339 |
| `https://www.ahsd.org/` | same | 200 | `text/html; charset=utf-8` | 176,036 |
| `https://www.ahsd.org/our_district/calendar` | same | 200 | `text/html; charset=utf-8` | 125,617 |
| `https://wav.ahsd.org/` | same | 200 | `text/html; charset=utf-8` | 98,029 |
| `https://wav.ahsd.org/our_school/calendar` | same | 200 | `text/html; charset=utf-8` | 69,446 |
| `https://hs.ahsd.org/` | same | 200 | `text/html; charset=utf-8` | 133,006 |
| `https://ms.ahsd.org/` | same | 200 | `text/html; charset=utf-8` | 134,541 |
| `https://cse.ahsd.org/` | same | 200 | `text/html; charset=utf-8` | 190,201 |
| HEAD `https://ms.ahsd.org/UserFiles/Servers/Server_453577/File/26.27%20Grade.5%20Supply%20List.pdf` | `https://cdnsm5-ss20.sharpschool.com/UserFiles/Servers/Server_453577/File/26.27%20Grade.5%20Supply%20List.pdf` | 301 then 200 | `application/pdf` | — |
| GET the same PDF | same CDN URL | 200 | `application/pdf` | 84,401 |

## F-1 — The platform is SchoolMessenger Presence

The district page footer carries `Website by <a href="https://www.schoolmessenger.com/school-website-design/">SchoolMessenger Presence</a>`.
A-001 is confirmed by capture rather than by recall.

## F-2 — `robots.txt` disallows every plausible calendar service path

The file reached after the redirect chain is, in full:

```text
#Global Level
User-agent: *
Disallow: /Search/
Disallow: /WebApi/
Disallow: /WebServices/
Disallow: /portal/svc/
Disallow: /Common/controls/StaffDirectory/ws/StaffDirectoryWS.asmx/
Disallow: /Common/controls/WorkspaceCalendar/ws/WorkspaceCalendarWS.asmx/
Disallow: /common/controls/General/CalendarPicker/CalendarPickerWS.asmx/
```

Two of the seven disallowed paths are calendar web services. `RobotsRules` already refuses them, so
this is a constraint Aleph enforces rather than a preference.

The redirect chain also confirms Q-001 from live behaviour: `/robots.txt` becomes `/robots.txt/` and
then `/robots.txt/Default.aspx`. The platform appends a trailing slash and a default document.

## F-3 — Calendar pages carry no event data

Both captured calendar pages render a client-side component and nothing else:

```html
<div class="reactComponent modernCalendarComponent"
     data-calendar-id="449450" data-portal-id="449353" data-portlet-instance-id="37495"
     data-initial-view-mode="Monthly" ...>
```

Waverly's page carries `data-calendar-id="454412"` and `data-portal-id="454338"`. The served HTML
contains the component's configuration and no events. Aleph does not execute scripts, so
`aleph.html:1` extracts the surrounding navigation text and nothing calendrical.

## F-4 — No calendar transport was investigated, because none was captured

The event request is issued by the page's script, not by the document. No such request was captured,
so there is no working request to investigate. The identifiers in F-3 would be enough to *construct*
a service URL, and constructing one is exactly what ALEPH-011 forbids — and F-2 shows that every
path such a URL would plausibly occupy is disallowed to `User-agent: *` anyway.

The negative result stands as the finding: **SchoolMessenger calendar event data is not reachable
within Aleph's declared policy.** Provider-specific calendar transport remains a separate capability
that would need its own authorization and its own robots answer, not a mechanical-extraction change.

## F-5 — Calendar paths are readable slugs

Captured calendar hrefs, all of them ordinary content paths:

- `/our_district/calendar` (district)
- `/our_school/calendar` (`hs`, `ms`, `cse`, `wav` — identical on all four)
- `/school_board/meeting_calendar`
- `/curriculum/assessments/assessment_calendar`

These captures, and only these, justify the `calendar_signals` patterns configured for the `ahsd`
source. The patterns are configuration precisely so that they stay answerable to evidence.

## F-6 — Calendar artifacts are published as Google Drive iframes

`https://wav.ahsd.org/our_school/calendar` contains:

```html
<iframe src="https://drive.google.com/file/d/1gXu9XTbR2TTbYC-bHrUxJHboIZHS1TDw/preview" width="640" height="480" allow="autoplay">
```

This is the live form of the critical proof: a calendar page reaching its PDF artifact through an
`iframe` edge to a host Aleph will never crawl.

One district-wide artifact, `drive.google.com/file/d/1HUzm_HvLaTonYVkv6l96sFDRwJ8MtQsY/preview`, is
iframed from four allowed-host homepages (`hs`, `ms`, `cse`, `wav`), so a single external resource
holds four parents in Funes discovery provenance.

Captured external embed hosts: `drive.google.com` (6), `docs.google.com` (2), `www.facebook.com` (2).

## F-7 — District PDFs redirect off the allowed hosts

PDFs are published under `/UserFiles/Servers/Server_<portal id>/File/…` and 301 to a CDN:

```text
https://ms.ahsd.org/UserFiles/…/26.27%20Grade.5%20Supply%20List.pdf
  -> https://cdnsm5-ss20.sharpschool.com/UserFiles/…/26.27%20Grade.5%20Supply%20List.pdf
```

The frontier admits the request because `ms.ahsd.org` is allowed; the fetcher follows the redirect
and preserves the bytes. Because Funes identity is the canonical *final* URL, every district PDF is
stored under a `sharpschool.com` resource reference. The inventory keeps both — `canonical_url` is
the district-facing identity, `final_url` is what was actually retrieved. See Q-008.

## F-8 — `aleph.pdf:1` works on real district bytes

The captured 84,401-byte PDF (`sha256
b5d712e5f31420de1b4f7881d78904c89a99d1b9b3e88cca025163a30727d365`) yields 832 characters of embedded
text beginning `Abington Heights Middle School 1555 Newton Ransom Boulevard…`. The extractor was
exercised against production bytes, not only against the synthetic fixture.

## F-9 — Hosts the seeds do not name

Allowed by `*.ahsd.org` but absent from the configured seeds, and therefore reachable only by
discovery: `sa.ahsd.org`, `nr.ahsd.org`. A-002's "newly discovered subdomains enter the frontier" is
now a captured fact rather than a hypothetical.

External link hosts captured: `ahsd.ss20.sharpschool.com`, `cdnsm1/2/4/5-ss20.sharpschool.com`,
`cdnsm1-sstemplatefonts.sharpschool.com`, `go.schoolmessenger.com`, `asp.schoolmessenger.com`,
`www.schoolmessenger.com`, `abingtonpa.infinitecampus.org`, `ahsdathletics.org`,
`www.metzabingtonheights.com`, `www.applitrack.com`, `www.safe2saypa.org`, `twitter.com`,
`www.youtube.com`, `www.facebook.com`.

## Not investigated

- Any `/WebApi/`, `/portal/svc/`, `/WebServices/`, or `*.asmx` service. Refused by F-2.
- The external artifact hosts themselves. `drive.google.com` is recorded as provenance and never
  requested; D-009 and the crawl boundary hold.
- Authenticated surfaces. A-005 stands: public GET and HEAD only.
