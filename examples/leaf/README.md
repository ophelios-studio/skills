# Leaf example

Minimal Binary-tier Leaf project. Mirrors the shape of leaf.ophelios.com
itself (which is built with Leaf), trimmed to the fewest files needed
to demonstrate the skill's empirical patterns.

## Layout

```
config.yml                                  # Site config (the leaf: section drives everything)
content/getting-started/intro.md            # Sample page with YAML frontmatter
templates/layouts/docs.latte                # Override stub (path MUST match bundled exactly)
public/assets/css/app.css                   # Minimal theme via CSS variables (no bundler)
```

## Run

```bash
# Install the binary if you haven't:
curl -fsSL https://leaf.ophelios.com/install.sh | sh

# Dev server with live reload at http://localhost:8080
leaf dev

# Static build to ./dist/
leaf build
```

## What this demonstrates

- **Bare Binary-tier shape** — no `app/`, `vendor/`, or `composer.json`.
- **Frontmatter-driven page metadata** (`title`, `order`,
  `description`).
- **Section ordering via `leaf.sections` in config** — affects the
  auto-generated sidebar.
- **Override path-match rule** — `templates/layouts/docs.latte` lines
  up with the bundled `app/Views/layouts/docs.latte`. A typo silently
  falls back to the bundled default.
- **`DEV_SERVER` constant** gates live-reload polling so it never ships
  in `dist/` for production.
- **CSS variables for theming** — no Tailwind build, no preprocessor;
  toggle `data-theme` to switch.

## What's NOT here

- Multi-locale (`content/fr/...`, `locale/fr/general.json`)
- Custom pages (`templates/pages/about.latte`)
- Post-build hooks (`leaf.post_build` in config)
- A full theme (this stub assumes you'll either rely on bundled
  defaults or build out the override tree)

For all of those plus the production patterns, study
`zephyrus-leaf-site` directly — it's the canonical real-world Leaf
project.
