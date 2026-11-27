# Leaf example

Minimal Binary-tier Leaf project: one Markdown page, a head override for
SEO and a small retheme. Verified with the leaf CLI v1.0.0.

## Layout

```
config.yml                          # Site config (the leaf: section drives everything)
content/getting-started/intro.md    # A page with YAML front matter
templates/partials/head.latte       # Override: bundled head plus canonical, hreflang, og:image, site.css
public/assets/css/site.css          # Retheme: the theme's public CSS properties
```

## Build it

Install the binary (it needs PHP 8.4 with `intl`, `mbstring` and
`tokenizer` on `PATH` to build):

```bash
curl -fsSL https://leaf.ophelios.com/install.sh | sh
```

Then, from this directory:

```bash
leaf build    # writes dist/: 3 pages, search.json, sitemap.xml, robots.txt
leaf dev      # serves dist/ at http://localhost:8080 and rebuilds on change
```

Or from anywhere: `leaf build --dir examples/leaf`. The output lands in
`examples/leaf/dist/`, which is not meant to be committed here.

## What it demonstrates

- **Bare Binary-tier shape**: no `app/`, `vendor/` or `composer.json`;
  the bundled theme and framework come from the binary.
- **Front matter** (`title`, `order`, `description`) driving the sidebar
  and the meta description.
- **`leaf.sections`** ordering and labelling the sidebar (a section folder
  it does not list still shows, after the declared ones).
- **Exact-path override**: `templates/partials/head.latte` replaces the
  bundled file of the same path, copied from the 1.0.0 theme. It keeps the
  theme's `tailwind.css` link (no Tailwind CDN: the theme ships the compiled
  sheet) and its document titles, which already use a middle dot in 1.0.0.
- **Canonical, hreflang and og:image**, which the bundled head does not
  emit, built from `$section`, `$slug` and `$pagePath`, since nothing on the
  Binary tier sets `$requestPath`. `production_url` is set, so the build also
  writes `sitemap.xml` and `robots.txt`. The og:image file itself
  (`public/assets/og/og-default.png`, 1200x630) is not included.
- **Retheming without replacing `app.css`**: `public/assets/css/site.css`
  sets the theme's public properties (`--accent`, `--accent-light`) under
  `:root`, `[data-theme="light"]` and `:root:not([data-theme="dark"])` in
  `@media (prefers-color-scheme: light)`, and the head links it after the
  theme's stylesheets. A file at the bundled path (`app.css`) would replace
  the whole stylesheet instead.

## Not here

Multi-locale content (`content/fr/...`, `locale/fr/*.json`), custom pages
(`templates/pages/about.latte`) and `leaf.post_build` hooks. For a real
multi-locale site with SEO overrides and a sitemap hook, see
https://github.com/zephyrus-framework/site.
