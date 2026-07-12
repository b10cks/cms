---
description: "Generate and browse the machine-readable OpenAPI specs for all b10cks APIs."
---

# Generated OpenAPI Specs

b10cks generates OpenAPI 3.0 specifications for its APIs **from the code** — routes, form requests, API resources, and filter classes are analyzed so the reference never drifts from the implementation.

## Generating

```bash
php artisan docs:generate                     # all configured prefixes
php artisan docs:generate --prefix=api/v1     # one prefix
php artisan docs:generate --format=yaml      # YAML instead of JSON
php artisan docs:generate --output=/some/dir # custom output directory
```

By default one spec file per API prefix is written to `docs/public/specs/`, from where the documentation build serves them at `/docs/specs/`:

| File | API | Browsable reference |
| --- | --- | --- |
| `api_v1.json` | [Data API](data-api.md) | [Data API Reference](reference/data-api.md) |
| `mgmt_v1.json` | [Management API](management-api.md) | [Management API Reference](reference/management-api.md) |
| `auth_v1.json` | Auth API | [Auth API Reference](reference/auth-api.md) |

The reference pages are rendered from the specs by the documentation site itself, so a running instance serves its own interactive reference at `https://your-instance/docs/api/reference/data-api`. The raw specs are available at `https://your-instance/docs/specs/api_v1.json` (etc.) for tooling and code generation.

## What the generator derives

- **Operations** — every route under the configured prefixes (`config/docs.php` → `routes.prefixes`), with per-prefix security schemes (query token for the Data API, Bearer for management).
- **Summaries & descriptions** — from the controller method's doc block (falling back to the class doc block for single-action controllers, then to a verb+resource summary). Write normal PHPDoc text on a controller method and it appears in the spec.
- **Tags** — from the route's resource segment (tenancy scopes like `spaces/{space}` are skipped), so operations group by resource in spec browsers.
- **Query parameters** — from `Filter` classes (one parameter per filter method, honoring `@filterDescription`, `@filterType`, `@filterFormat`, `@filterExample` annotations, plus a `sort` enum from `$sortableColumns`) and from validation rules in `FormRequest` classes on GET routes.
- **Request bodies** — from `FormRequest` validation rules on write routes.
- **Response schemas** — from API `Resource`/`ResourceCollection` classes, discovered via return types and `@response` annotations (e.g. `@response ContentResourceCollection<LengthAwarePaginator<ContentResource>>`). Shared schemas are emitted once under `components/schemas` and referenced.
- **Operation IDs** — stable, collision-free IDs derived from the method and full path (`get_contents`, `delete_spaces_tokens_by_token`).

## Annotating code for better docs

When adding an endpoint:

1. **Write a doc block** on the controller method — the first sentence becomes the summary, the whole text the description.
2. **Type your responses** — return a dedicated Resource, or add `@response` when the return type alone is ambiguous.
3. **Document filters** — on filter methods, add `@filterDescription` / `@filterType` / `@filterFormat` / `@filterExample`; class-level `@filterDescription` and `@sortDescription` cover the general case.
4. Regenerate with `php artisan docs:generate` and check the output in `docs/public/specs/`.

## Configuration

`config/docs.php` controls the output directory, per-prefix titles/descriptions/security, security scheme definitions, resource discovery directories, default response descriptions, and pagination defaults.
