---
description: "Ilum transforms images on the fly via URL operations: resize, crop, gravity, focal points, and format conversion — with an interactive playground."
---

# Image Service (Ilum)

Ilum is b10cks' built-in image transformation service. It resizes, crops, and re-encodes assets **on the fly** via URL path operations — no pre-generated renditions, no external service, no upload-time configuration. You describe the image you want in the URL; Ilum computes it on first request and every subsequent request is served from cache.

This design has a few practical consequences:

- **The URL is the API.** There's no SDK call to make and nothing to configure per asset — any variant you can express as an operation string exists the moment you request it.
- **Variants are free until used.** Because nothing is pre-generated, adding a new breakpoint to your frontend doesn't require reprocessing your media library.
- **URLs are immutable per asset version.** Replacing an asset produces a new asset path, so transformed URLs can be cached forever (`Cache-Control: immutable`, 1-year max-age) and put behind any CDN.

Under the hood Ilum uses [libvips](https://www.libvips.org/) (with an ImageMagick fallback), which streams pixels instead of loading full bitmaps — resizing a 40-megapixel photo takes tens of milliseconds and little memory. Oversized sources (>100 MP by default) are rejected before decoding as a decompression-bomb guard.

## URL anatomy

```
https://<ilum-host>/<storage>/<space>/<assetId>/<filename>                 # original
https://<ilum-host>/<storage>/<space>/<assetId>/<filename>/<operations>   # transformed
```

Asset fields deliver a `full_path` that already contains everything up to the filename. To transform, append a `/` and a comma-separated list of operations; `format` and `quality` go in the query string:

```
…/IMG_1505.jpeg                                  # original, streamed as-is
…/IMG_1505.jpeg/w_800                            # 800px wide, proportional
…/IMG_1505.jpeg/w_800,h_450,c_fill,g_face        # exact 800×450, cropped around faces
…/IMG_1505.jpeg/w_1200,h_630,c_fill?format=jpg&quality=80
```

A real example against a live asset:

```
https://app.b10cks.com/ilum/01k13p9615ysd6g3zzffkc527j/01k119agamfvn54vb7tnjn87zr/01kxx5prnge07etb8w5682h18v/IMG_1505.jpeg/w_600,h_600,c_fill,g_face
```

Each operation is `key_value`; order within the list doesn't matter. Invalid combinations return a `422` JSON error explaining what's wrong rather than a broken image.

## Playground

Build an operation string against two live demo assets and watch the result update. The generated URL is exactly what you'd put in an `<img src>`.

<IlumPlayground />

## Operations reference

Path operations (comma-separated, after the filename):

| Op | Example | Meaning |
| --- | --- | --- |
| `w_{px}` | `w_800` | Target width in pixels (max 5000) |
| `h_{px}` | `h_450` | Target height in pixels (max 5000) |
| `c_{mode}` | `c_fill` | Crop mode: `fill`, `fit`, or `crop` |
| `g_{gravity}` | `g_face` | Gravity for `fill`: `face`, `center`, `auto`, or a focal point |
| `x_{px}` / `y_{px}` | `x_120,y_40` | Top-left offset for manual `crop` |
| `tw_{px}` / `th_{px}` | `tw_400,th_300` | Target size when combining `crop` with a resize (must be set together) |

Query parameters:

| Param | Example | Meaning |
| --- | --- | --- |
| `format` | `format=webp` | Output format: `webp`, `avif`, `jpg`, `png` |
| `quality` | `quality=80` | Encoder quality, 1–100 |

Dimensions beyond the configured maximum (5000px by default) are clamped, not rejected — a `w_9000` request silently becomes `w_5000`.

### Crop modes

**No `c` — plain resize.** `w` or `h` alone resizes proportionally; the other dimension follows the aspect ratio. Both together resize to fit *within* the `w×h` box without cropping — the result matches at least one of the two dimensions exactly.

```
…/photo.jpg/w_800          # 800px wide, height follows
…/photo.jpg/h_600          # 600px tall, width follows
…/photo.jpg/w_800,h_600    # fits inside 800×600, aspect preserved
```

**`c_fill` — fill the exact box.** The result is always exactly `w×h`; whatever doesn't fit the target aspect ratio is cropped away. Requires both `w` and `h`. *Where* it crops is decided by gravity:

- `g_face` — detects faces and keeps them in frame. The go-to for avatars and team photos.
- `g_auto` — crops around the most visually salient region (entropy/attention based). A good default for arbitrary editorial images.
- `g_center` — plain center crop.
- `g_{X}p_{Y}p` — a fixed focal point in percent of the source image, e.g. `g_30p_60p` keeps the point at 30% from the left and 60% from the top in frame across every target aspect ratio. Ideal when editors set a focal point once and the frontend renders many crops.
- No `g` — behaves like a center-weighted fit.

```
…/team.jpg/w_400,h_400,c_fill,g_face        # square avatars
…/hero.jpg/w_1600,h_400,c_fill,g_30p_60p    # ultrawide banner, subject stays in frame
```

**`c_fit` — fit within the box.** Same as the no-`c` two-dimension resize, but valid with a single dimension too. Never crops, never upscales beyond what you ask for.

**`c_crop` — manual crop.** Cuts a `w×h` region starting at offset `x`/`y` (source pixels, default `0,0`). Add `tw`/`th` to also resize the cropped region — e.g. cut a 800×600 region, then downscale it to 400×300:

```
…/photo.jpg/x_200,y_150,w_800,h_600,c_crop              # cut region, keep size
…/photo.jpg/x_200,y_150,w_800,h_600,c_crop,tw_400,th_300 # cut region, then resize
```

Use manual crop when coordinates come from your own UI (an editor-drawn crop box); use `fill` + gravity for everything automated.

## Formats and quality

When any transformation is requested and no `format` is given, Ilum re-encodes to the instance default (**webp** out of the box) — so `w_800` alone already gives you a modern format. Request the untouched original by using the URL without operations.

| Format | Default quality | Notes |
| --- | --- | --- |
| `webp` | 85 | Default output; broad support, good compression |
| `avif` | 85 | Best compression; encoding is slower (first request only) |
| `jpg` | 85 | For consumers that need universal support (e.g. some OG-image scrapers) |
| `png` | 90 | Lossless-ish; use for screenshots/diagrams with hard edges |

Animated sources (GIF, animated webp) keep their animation: if the target format can't animate, Ilum falls back to `gif` automatically.

`quality` trades file size against fidelity. For photographic content `quality=60`–`75` in webp/avif is usually indistinguishable at display size; the default 85 is conservative.

## Recipes

**Responsive `srcset`** — one asset, many widths, browser picks:

```html
<img
  src="…/hero.jpg/w_1280"
  srcset="…/hero.jpg/w_640 640w, …/hero.jpg/w_1280 1280w, …/hero.jpg/w_1920 1920w"
  sizes="(max-width: 768px) 100vw, 1280px"
  alt="…"
>
```

**Art direction** — different aspect ratios per breakpoint, focal point keeps the subject framed in all of them:

```html
<picture>
  <source media="(max-width: 640px)" srcset="…/hero.jpg/w_640,h_800,c_fill,g_30p_40p">
  <img src="…/hero.jpg/w_1600,h_640,c_fill,g_30p_40p" alt="…">
</picture>
```

**Social / OG images** — scrapers prefer jpg and a fixed 1200×630:

```
…/cover.jpg/w_1200,h_630,c_fill,g_auto?format=jpg&quality=80
```

**Avatars at every size from one upload:**

```
…/portrait.jpg/w_48,h_48,c_fill,g_face      # comment thread
…/portrait.jpg/w_200,h_200,c_fill,g_face    # profile page
```

## Framework integration

For Nuxt, a ready-made `@nuxt/image` provider generates these URLs (including responsive `srcset`) — see the [Nuxt guide](../guides/nuxt.md#5-images-with-nuxtimage-and-ilum). In other frameworks, build the operation string yourself or port the provider (it's ~100 lines of URL assembly).

## Caching and delivery

Transformation results are cached server-side with the URL as the cache key, so a given operations string is computed exactly once per asset. Responses are served with `Cache-Control: public, max-age=31536000, immutable` and permissive CORS headers — put a CDN in front of the Ilum host and every variant becomes an edge hit after its first request.

Non-image assets requested through Ilum URLs (PDFs, videos, …) are streamed through unchanged with the same cache headers, plus hardening headers (`nosniff`, restrictive CSP with `sandbox`) that make user-uploaded SVGs safe to serve inline.
