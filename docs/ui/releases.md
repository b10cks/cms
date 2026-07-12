---
description: "Creating releases, assigning versions, review, and publishing."
---

# Releases

A **release** is a bundle of content changes that goes live as one — the product launch where the landing page, navigation, and blog post must appear together, not one by one. Prepare everything behind the scenes, review it as a set, then publish it in a single moment (or schedule that moment). Concept background: [Releases](../concepts/releases.md).

## Creating a release

*New Release* asks for a name (e.g. "Spring campaign") and description. The new release starts in the **Draft** state.

## Filling a release

While editing content, save or publish changes **into the release** instead of publishing directly — the affected entry's version is attached to the release, and the live site stays untouched. The release detail page lists every item it contains.

## States

| State | Meaning |
| --- | --- |
| **Draft** | Open for changes; items can be added and removed |
| **Scheduled** | Has a *Scheduled at* time; will be published automatically |
| **Published** | All contained versions went live atomically |

The list view groups scheduled vs. unscheduled releases and shows item counts and timestamps.

## Publishing

Publish a release immediately or set its schedule. All contained versions become the published versions of their entries in one operation — the space's content revision flips once, so frontends and caches switch to the complete new state together.

Deleting a release does not delete the content versions in it; they remain in each entry's history.
