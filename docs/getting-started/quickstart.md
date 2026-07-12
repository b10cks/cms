---
description: "From an empty space to a rendered page in ~10 minutes: model a block, create content, publish, and fetch it from the Data API."
---

# Quickstart

From an empty space to content served through the API in about ten minutes. This walkthrough uses the admin UI (b10cks Cloud or your self-hosted instance) and plain `curl`; swap in an SDK at the end.

## 1. Create a space

After signing in, click **Create space**, give it a name, and pick a plan (self-hosted instances have this too — spaces are how b10cks isolates projects). The new space starts with an empty content tree.

## 2. Model your first block

Content types in b10cks are called **blocks**. You need at least one *root* block — a block that can be a content entry of its own:

1. Go to **Blocks** in the space navigation and click **New block**.
2. Name it `Page`, set its type to **Root**, and save.
3. Add fields to the schema — for a minimal page:
   - `headline` — **Text**, translatable
   - `body` — **Rich text**, translatable
   - `blocks` — **Blocks** (a nested block list, so pages can be composed from smaller blocks later)
4. Save the block.

Add a second, *nestable* block (e.g. `Teaser` with `title` and `text` fields) to see composition in action — nestable blocks can only live inside other blocks' **Blocks** fields.

The full field-type catalogue is in [Fields](../concepts/fields.md).

## 3. Create and publish content

1. Go to **Content** and click **New content**.
2. Choose the `Page` block, name the entry `Home`, and set its slug to `home`.
3. Fill in the fields. In the **Blocks** field, add a `Teaser` and fill it in too.
4. Click **Publish**.

Until you publish, your work only exists as a draft version. See [Versions & publishing](../concepts/versions-and-publishing.md).

## 4. Create an access token

Go to **Settings → Access tokens** and create a token. This token authenticates read access to the space's Data API.

## 5. Fetch it

```bash
curl "https://api.b10cks.com/api/v1/contents/home?token=YOUR_TOKEN"
```

(Self-hosted: replace the host with your instance, e.g. `https://cms.example.com/api/v1/...`.)

You get the published entry as JSON:

```json
{
  "data": {
    "id": "01JW…",
    "name": "Home",
    "slug": "home",
    "full_slug": "home",
    "block": "page",
    "content": {
      "headline": "Hello world",
      "body": { "type": "doc", "content": [ … ] },
      "blocks": [
        { "id": "…", "block": "teaser", "title": "…", "text": "…" }
      ]
    },
    "published_at": "2026-07-12T09:30:00Z"
  }
}
```

A few things to notice:

- `block` tells you which component to render (`page` → your `Page` component).
- Nested blocks repeat the same shape recursively — rendering is one recursive component. The SDKs ship that component for you.
- Add `vid=draft` to fetch the draft version instead of the published one.

## 6. Build a frontend

Pick your framework guide and wire the API into real components:

- **[Nuxt](../guides/nuxt.md)** (recommended; there's a ready-made [boilerplate](https://github.com/b10cks/nuxt-boilerplate))
- [Vue](../guides/vue.md) · [React](../guides/react.md) · [Next.js](../guides/nextjs.md) · [Svelte](../guides/svelte.md) · [JavaScript](../guides/javascript.md)

Then set the **preview URL** in the space's visual editor settings to your dev server and edit your site live — see [Live preview & visual editing](../guides/live-preview.md).
