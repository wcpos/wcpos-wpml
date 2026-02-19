# WCPOS WPML Integration

Adds WPML-aware product filtering to WCPOS, including **fast sync route coverage** and a per-store language selector for WCPOS Pro stores.

## What it does

- Filters WCPOS product + variation REST queries by language.
- Intercepts WCPOS fast-sync routes (`posts_per_page=-1` + `fields`) so duplicate translated products are not returned.
- Free WCPOS stores default to WPML default language.
- WCPOS Pro stores can save a store-specific language.
- WCPOS Pro store edit gets a **Language** section with explanatory help text.
- Store language selector UI only loads when WPML is active and languages are available.
- Plugin strings use the `wcpos-wpml` text domain.
- PHP integration now no-ops when WPML is unavailable.
- Optional version gates via:
  - `wcpos_wpml_minimum_wpml_version`
  - `wcpos_wpml_minimum_wcml_version` (defaults to `4.11.0` when WCML version is detectable)
- Tests are mocked compatibility tests (WPML itself is proprietary and not installed in CI).

## Development

```bash
pnpm test
```
