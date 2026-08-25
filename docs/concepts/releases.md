---
description: "Group content versions and publish them together atomically — with scheduling and review."
---

# Releases

A **release** groups content versions from many entries and publishes them together — atomically, now or at a scheduled time. Use releases for anything that must go live as a unit: a product launch (landing page + navigation + pricing + blog post), a seasonal campaign, a site-wide rebrand.

## How releases work

1. **Create a release** with a name and description (**Releases** in the space navigation).
2. **Assign versions** — while editing content, save changes *into* the release instead of publishing them directly. Each affected entry gets a version tagged with the release; the live site is unaffected.
3. **Review** — the release page lists every entry/version it contains. Preview the combined state before committing.
4. **Publish** — immediately, or set a `publish_at` time for scheduled go-live. All contained versions become the published versions of their entries in one operation.

The space's content revision changes once, so caches and frontends flip to the complete new state together — visitors never see a half-launched mix.

## Release lifecycle

| State | Meaning |
| --- | --- |
| Open | Versions can be added and removed |
| Scheduled | `publish_at` set; the scheduler will publish it |
| Published | All versions went live; the release records `published_at` for audit purposes |

A deleted (soft-deleted) release does not delete the content versions in it — they remain part of each entry's history.

## Releases vs. scheduled publishing

Scheduling a *single entry* needs no release — versions support `scheduled_at` directly ([Versions & publishing](versions-and-publishing.md#going-live)). Reach for a release when **multiple entries** must change state together, or when you want a named, reviewable bundle for a launch.

## Related

- [Releases (user guide)](../ui/releases.md) — the UI walkthrough
- [Versions & publishing](versions-and-publishing.md)
