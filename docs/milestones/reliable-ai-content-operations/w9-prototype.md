# W9 prototype evidence

One customer file went through the product over real HTTP and a real browser session, on the local `Test` space (default `en`; enabled `en`, `de`, `de-at`, `fr`, `fr-ch`). A throwaway script outside the repo converted it. Neither is committed.

## What went in

188 external concepts. 13 internal-only ones stayed out, and the broad synonym lists stayed out. 12 are bans with `*` entries, and one conditional term has a note. Only forms of the terms starting with an inflected adjective went into Also written as.

| Step | Result |
| --- | --- |
| Upload, streaming | 188 added, 0 row errors, 0.4 s |
| Same file again | 188 unchanged |
| XLSX export, then import | 188 unchanged |

## Prompt for the same 429-character paragraph

| | W7 | W8 | W9 |
| --- | --- | --- | --- |
| Characters | 15,865 | 992 | 674 |
| Terms | 17 | 15, 3 of them context | 12 |

The wrong order from W8 is gone: "Ergonomischen Liegezentrum" now yields "sleep ergonomics center". An inflected "dealers", "Händlern" and "klimaneutrale" are flagged through `*`.

## Rendered in the real app

Checked in headless Chromium with a real session: list, regional columns folded, `/` search, keyboard open, sheet with wording first, ban row, Try a text from the menu, Ask AI copy and one Start, phone list and sheet, no page overflow, no page errors or 5xx. The tree mark and Publish warning were clicked in the real app with the flag answer mocked and the publish call blocked.

That found one defect the tests could not: a marked row at a narrow tree lost its name to zero width. Fixed, and the name now keeps 24px.

A test click published the local `For Startups` entry in `Test`, since Publish opens no dialog when there are no flags. Local data only.

## Not exercised

- A real provider. This covers Fill missing with AI, meta button runs, how far a model follows the short prompt and fixes findings.
- The warning's Open links, `?field=` focus and the conflict link opening the dock.
- MySQL for the thread status SQL, real Reverb, Excel or Numbers, real phones and a screen reader.

## Before deploying

Run `spaces:repair-databases` by hand for space migration `000028`. Until then AI writes and the thread list fail on the missing column.

## Editorial

The bans' `avoid` lists came from the file's synonyms for 10 of the 12 and may be too broad, such as "refund". The glossary's owners should read them.
