# Leaf example

Minimal Binary-tier Leaf project: one Markdown page and the two template
overrides most real sites need. Verified with the leaf CLI v0.1.6.

## Layout

```
config.yml                          # Site config (the leaf: section drives everything)
content/getting-started/intro.md    # A page with YAML front matter
templates/layouts/docs.latte        # Override: bundled docs layout, middle dot in <title>
templates/partials/head.latte       # Override: bundled head, middle dots, canonical, hreflang, og:image
public/assets/css/app.css           # Replaces the bundled stylesheet (same path)
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
- **`leaf.sections`** ordering the sidebar (a section folder it does not
  list would build but stay out of the sidebar).
- **Exact-path overrides**: `templates/layouts/docs.latte` and
  `templates/partials/head.latte` replace the bundled files of the same
  path. Their `{include}` paths are relative to the including file
  (`'../partials/head.latte'`).
- **No em-dash**: the bundled theme separates titles with an em-dash in
  three places; these two overrides swap all of them.
- **Canonical, hreflang and og:image** built from `$section`, `$slug` and
  `$pagePath`, since nothing on the Binary tier sets `$requestPath`.
  `production_url` is set, so the build also writes `sitemap.xml` and
  `robots.txt`. The og:image file itself
  (`public/assets/og/og-default.png`, 1200x630) is not included.
- **`public/` replaces bundled assets by path**: this `app.css` replaces
  the bundled stylesheet (so the docs layout loses most of its styling);
  the bundled scripts in `public/assets/js/` still ship because this
  project has none of its own.

## Not here

Multi-locale content (`content/fr/...`, `locale/fr/*.json`), custom pages
(`templates/pages/about.latte`) and `leaf.post_build` hooks. For a real
multi-locale site with SEO overrides and a sitemap hook, see
https://github.com/ophelios-studio/zephyrus-site.
