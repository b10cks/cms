---
description: "Ilum transforms images on the fly via URL operations: resize, crop, gravity, focal points, and format conversion."
---

# Image Service (Ilum)

Ilum is b10cks' built-in image transformation service. It resizes, crops, and re-encodes assets **on the fly** via URL path operations — no pre-generated renditions, no external service. Results are cached after the first request.

## URL anatomy

```
https://<ilum-host>/<storage>/<space>/<assetId>/<filename>                    # original
https://<ilum-host>/<storage>/<space>/<assetId>/<filename>/<operations>      # transformed
```

Asset fields deliver a `full_path` that already contains everything up to the filename — append a `/` and the comma-separated operations:

```
…/hero.jpg/w_800,h_450,c_fill,g_face
…/hero.jpg/w_1200,h_630,c_fill?format=webp&quality=80
```

## Operations

| Op | Example | Meaning |
| --- | --- | --- |
| `w_{px}` | `w_800` | Target width |
| `h_{px}` | `h_450` | Target height |
| `c_{mode}` | `c_fill` | Crop mode: `fill`, `fit`, or `crop` |
| `g_{gravity}` | `g_face` | Gravity for `fill`: `face`, `center`, `auto`, or a focal point |
| `x_{px}` / `y_{px}` | `x_120,y_40` | Offsets for manual `crop` |
| `tw_{px}` / `th_{px}` | `tw_400,th_300` | Target size when combining `crop` with a resize |

Query parameters (not path ops):

| Param | Example | Meaning |
| --- | --- | --- |
| `format` | `format=webp` | Output format: `webp`, `avif`, `jpg`, `png` |
| `quality` | `quality=80` | Encoder quality |

### Crop modes

- **No `c`** — `w` or `h` alone proportionally resizes; `w` and `h` together fit within the box (no cropping).
- **`c_fill`** — fills the exact `w×h` box, cropping overflow. Where it crops is decided by gravity:
  - `g_face` / `g_auto` — smart cropping around detected faces / salient regions
  - `g_center` — center crop
  - `g_{X}p_{Y}p` — focal point in percent, e.g. `g_30p_60p` keeps the point at 30% x / 60% y in frame
- **`c_fit`** — resize to fit within `w×h`, no cropping.
- **`c_crop`** — manual crop at `x`/`y` with size `w×h`; add `tw`/`th` to also resize the cropped region.

## Examples

```
…/team.jpg/w_400,h_400,c_fill,g_face            # square avatars, faces centered
…/hero.jpg/w_1600,h_900,c_fill,g_30p_60p        # art-directed focal point
…/chart.png/w_1200,c_fit?format=webp            # proportional + modern format
…/photo.jpg/x_200,y_150,w_800,h_600,c_crop,tw_400,th_300   # manual crop, then downscale
```

## Framework integration

For Nuxt, a ready-made `@nuxt/image` provider generates these URLs (including responsive `srcset`) — see the [Nuxt guide](../guides/nuxt.md#5-images-with-nuxtimage-and-ilum). In other frameworks, build the operation string yourself or port the provider (it's ~100 lines of URL assembly).

## Caching

Transformation results are cached server-side; the URL is the cache key, so a given operations string is computed once. Put a CDN in front of the Ilum host for edge delivery — URLs are immutable per asset version, making them safely cacheable with long TTLs.
