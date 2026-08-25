---
description: "Your own icon set inside the CMS: uploading SVGs, importing whole icon sets, adjusting colours, and serving your brand icons through standard Iconify tooling."
---

# Icons

The **Icons** section is your project's own icon set, managed in the CMS rather than in the website's code.

::: tip What makes this unusual
Most content management systems treat an icon as just another uploaded image. b10cks treats your icons as a real **icon set**: searchable, tagged, colour-aware, and served through the same protocol that public icon libraries like Iconify use.

The practical consequence is that your brand icons behave exactly like the 200,000 public icons your developers already work with. The same one-liner in code, the same tooling, no sprite build, no deployment. A designer uploads a new icon in the morning, and an editor can place it in a page that afternoon, with nobody touching the website's source.
:::

Icons must be **SVG** files. SVG is a drawing format rather than a photo format, which is why an SVG icon stays sharp at any size and can take on the colour of the text around it. If somebody hands you a PNG, ask for the SVG.

## Uploading

Drop `.svg` files into the upload dialog, one at a time or many at once. Each icon gets:

**A key**, made of lowercase letters, numbers, and hyphens, unique within the space. This is the icon's technical name, the one that ends up in the website's code, so `arrow-right` is a good key and `Arrow Right (final2)` is not. Keys are checked as you type: an invalid character, a duplicate inside the same upload, or a key that already exists is flagged before anything is saved. Treat a key as permanent once pages use it.

**A name and description**, for humans and for search.

**Tags**, for filtering. A batch upload can apply the same tags to every file in it, which is how you keep a hundred icons manageable from the first minute.

### Importing a whole icon set

Beyond single files, **Import icon set** reads an Iconify JSON file, which is the format entire icon libraries are published in. That turns "we bought an icon set" into one import instead of 300 uploads.

Two modes:

- **Add or overwrite** adds new icons and replaces existing ones that use the same key. The safe default.
- **Prune and replace** deletes every icon in the space first, then imports the set. Only for a deliberate reset, and the dialog warns you accordingly.

Afterwards a summary reports what was imported, updated, pruned, and what failed.

## Managing icons

The library lists every icon, searchable by key or name and filterable by tag. Opening one offers:

**Replace SVG** puts a new drawing behind the same key. Everything already using the icon picks up the new version, so refreshing an icon across the whole site is one upload.

**Edit SVG source** lets you adjust the file's markup directly and set its width and height. This one is for people comfortable reading SVG.

**Replace colours** rewrites the fixed colours inside the file so the icon takes on the colour of the text next to it instead. This is what you want for almost every interface icon: one file that looks correct on a white page, on a dark banner, and in a hover state, without three variants existing. It does change the file, so the original colours are gone afterwards.

**Colour check** previews the icon on light, dark, and transparent backgrounds. A white icon that is invisible on white shows up here rather than on the live site.

## Using icons

**In content.** Your team adds an [icon field](../concepts/fields.md#icon) to a block. Editors then get a picker with two tabs: your own registry, and the public Iconify library searchable by keyword. What they may choose from is controlled by the field: only your own icons, your icons plus the entire public library, or your icons plus an approved list of public collections. That last option is the useful middle ground, where the team gets breadth without anybody dropping a random cartoon into a product page.

**On the website.** Developers consume the registry through endpoints that speak the Iconify protocol, which means standard tooling such as `@nuxt/icon`, `unplugin-icons`, or the Iconify web components works with your icons unmodified. Single icons can also be fetched as plain SVG. See [Icons](../concepts/icons.md#delivery-api).
