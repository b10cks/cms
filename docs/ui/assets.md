---
description: "The media library: uploading files, organizing them in folders, tags and collections, filling in image details, and sharing files with people outside your team."
---

# Asset manager

**Assets** is your media library: every image, video, document, and file your project uses, in one place. The word "asset" simply means one uploaded file plus everything b10cks knows about it, such as its description, its tags, and where it is used.

If you can work the file manager on your own computer, you can work this one. There are folders, you can select several files at once, you can cut and paste, and the keyboard does what you expect. The background is described in [Assets](../concepts/assets.md) and [Image service](../concepts/image-service.md).

## Browsing and finding files

**Two ways to look at the library.** A grid of thumbnails for browsing by eye, and a list for scanning a lot of files quickly. The folders, tags, and collections live in the sidebar.

**Selecting files** works like on your computer: click one to select it, hold <kbd>Shift</kbd> and click another to select everything in between, hold <kbd>⌘</kbd> on a Mac or <kbd>Ctrl</kbd> on Windows and click to add or remove single files. *Select all* takes everything that matches your current filter, including the files on the following pages, not just the ones you can see.

**The keyboard works too**, if you prefer it. Arrow keys move the selection, <kbd>Enter</kbd> opens a file, typing a name jumps to the first match, and cut and paste moves files between folders. Press <kbd>?</kbd> at any time for the list of shortcuts.

**Filtering** narrows the library by file type (image, video, audio, document and so on), by tag, by folder, by rights status, or by licence expiry. Filters combine, so "images tagged *hero* whose licence expires before March" is a single view.

## Folders, and the rules they can carry

Folders organize the library the way they do on a hard disk: create them, rename them, move them, nest them inside each other. Two things make them more than boxes.

**Deleting a folder never quietly deletes files.** b10cks stops you from removing a folder that still has content in it.

**Each folder can decide what information its files must carry.** Open a folder's settings and you can:

- make an inherited field **required or optional**, so alt text is mandatory in `Website images` but optional in `Internal drafts`,
- **switch fields off** where they make no sense,
- and **add fields that exist only here**, such as a `photographer` field inside `Press photos`.

Folders inside a folder inherit these rules automatically, so one setting covers a whole branch. When you upload into a folder, b10cks asks for exactly what that folder demands and nothing else. The [concept page](../concepts/assets.md#per-folder-metadata-rules) has the details.

> **What is alt text?** A short description of what an image shows. Screen readers read it out to people who cannot see the image, and search engines use it too. "Two people shaking hands in an office" is useful. "image1.jpg" is not.

## Tags

Tags cut across folders. A file lives in exactly one folder but can carry as many tags as you like, for example `hero`, `2026`, and `campaign-spring`. Each tag has a name, a colour, and an icon, which keeps them recognizable at a glance.

Deleting a tag never touches the files. It only removes the label. Use **Bulk tag** to add and remove tags on a whole selection in one dialog.

## Collections

Collections sit below folders and tags in the sidebar and gather files **across** folders. The same file can be in several collections at once. Create one with the **+** next to the collection list, give it a name, an icon, and a colour, then open it to see its files fill the grid. There are two kinds ([concept](../concepts/assets.md#collections)):

- **Manual collections** are curated by hand. Drag files onto the collection, or use **Add to collection** from a file's menu or the selection bar. *Remove from collection* takes a file back out without deleting it.
- **Smart collections** fill themselves and stay correct without maintenance. This is the one to reach for when a collection describes a rule rather than a hand-picked set, such as "everything tagged campaign-spring whose licence has not expired". Instead of adding files you write rules: pick a property such as filename, tags, size, folder, orientation, rights status, or a date, choose how to compare it, and give a value. Say whether **all** rules must match or **any** of them. The collection then stays current on its own as files change.

> **Remove is not delete.** Inside a collection, *Remove from collection* only takes the file out of that one collection. *Delete from library* removes the file everywhere it is used, permanently. The menu labels and the confirmation dialog spell out which is which, so the two can't be confused in a hurry.

## Uploading

Drag files anywhere into the library, or click *Browse files*. While the upload runs, a dialog walks you through what your space needs to know about them.

- **Required information is collected right away.** If alt text is mandatory here, you are asked for it now instead of being chased for it next week. A counter shows how many files still miss something.
- **Duplicates are noticed.** If a file looks identical to one already in the library, b10cks offers to use the existing file instead of storing a second copy. Uploading anyway remains your choice.
- **Images are measured automatically**, including their dimensions and their main colours. Videos get preview images grabbed from the footage, and one of them becomes the still picture shown before the video plays.
- If your space has several languages, translatable information can be filled in per language.

## Looking at one file

Open any file for the full picture. The arrow buttons step through the folder without closing the view.

### Details and description

Filename, alt text, title, description, and any custom fields your space or this folder defines, with a value per language where that applies. If you start typing and then try to navigate away, b10cks reminds you about the unsaved change.

### Focus point

::: tip Highlight
One image, every crop, the subject always in frame. Instead of uploading a wide version, a square version, and a tall version of the same photo, you mark the spot that must stay visible and let every format cut around it.
:::

Click the image to set its **focus point**: the spot that must remain visible whenever the website crops the image into a different shape. A wide header, a square card, and a tall banner all cut the picture differently, and the focus point is what keeps the face in the frame instead of the elbow. [How cropping works](../concepts/image-service.md#crop-modes).

### Posters and thumbnails

A **poster** is the still image shown before a video starts playing. Videos show the frames b10cks grabbed during upload, and clicking one jumps the player to that moment. If none of them is the shot you want, **Upload poster** replaces them with an image of your choosing: a designed title card, a frame from another take, anything. Every website using the video picks up the new poster automatically, with no re-linking. **Remove poster** brings the automatically grabbed frames back.

Every other kind of file that isn't an image, such as PDFs, archives, and audio, can be given a thumbnail the same way with **Upload thumbnail**. That image then appears wherever the file is shown: in the library, in the content editor, and on public share pages.

### Colours and readability

::: tip Highlight
b10cks tells you whether white or black text will actually be readable on an image, with real contrast numbers rather than a designer's guess. That is an accessibility decision most systems leave you to make by eye.
:::

For images, b10cks extracts the main colours and shows, for each one, how well black and white text would read on it, plus which of the two it recommends. That turns "will white text work on this photo" from a guess into a fact. Click any colour to copy its value.

### Rights and licensing

Who holds the copyright, which licence applies, what restrictions come with it, and when it expires. Each file shows its rights status as *unrestricted*, *restricted*, or *expired*. The status is informational and never blocks anybody's work, but nobody can claim they didn't see it. You can filter the whole library by rights status or by upcoming expiry, which makes a pre-campaign audit a two-minute job.

### Where this file is used

::: tip Highlight
Before you replace or delete anything, you can see every page that uses it. No searching, no guessing, no discovering the answer on the live site.
:::

A list of every page that uses this file, with its draft or published state. Check it before replacing or deleting anything, and you know exactly what you are about to affect.

### File history

**Replace media** swaps the file itself while keeping its identity, so every page using it gets the new file automatically and nobody has to re-link anything. The previous file is kept as an earlier version. Restoring an old version is itself recorded as a version, so even undoing can be undone.

### Quick actions

Copy the file's web address, open it in a new tab, download it, duplicate it, or delete it. Deleting always asks first, and asks more insistently when the file is still used somewhere.

## Working on many files at once

Select several files and the selection bar offers the bulk operations: **move to folder** (with a search box for finding the target), **bulk tag**, **add to collection**, **share**, **download**, **export**, and **delete**. Inside a manual collection it also offers **remove from collection**. Folders and files can be dragged together in a mixed selection.

## Sharing files with people outside your team

::: tip Highlight
Sending files to a photographer, a printer, or a journalist does not need a third-party file-sharing service, an export, or an account for the recipient. Publish a link straight from the library, optionally with a password, an expiry date, and a download limit, and revoke it the second it has served its purpose.
:::

You can publish a **collection**, a **folder**, or a **selection of files** as a public download link. Whoever receives it needs no b10cks account and no password unless you set one ([concept](../concepts/assets.md#sharing--downloads)).

**Creating and managing links.** From a collection's menu choose **Manage shares** to see its links, create new ones, copy or open them, and edit, revoke, or delete existing ones. Every link in the space is also listed under **Settings → Shares**, which is the place to review and clean up.

**Protecting a link.** Optionally set a password, an expiry date, and a maximum number of downloads, and decide whether recipients may download single files or only everything at once.

**What recipients see.** A clean page with preview thumbnails and a **Download all** button, which hands them a zip file that b10cks builds on the server. Individual file downloads appear when you allowed them. Revoking a link cuts off access immediately, even for people who already have it open.

## Import and export

**Export** writes the information about your files, not the files themselves, into a spreadsheet or exchange file: JSON, CSV, Excel, XLIFF, or YAML. **Import** reads an edited file back in and reports row by row what was created, what changed, and what failed.

This is how large clean-ups get done in an afternoon. Fix several hundred alt texts in a spreadsheet, or send the XLIFF file to a translation agency and import the translated descriptions when they come back.
