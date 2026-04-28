---
title: Introduction
order: 1
description: Welcome to the example Leaf site.
---

# Introduction

This is a minimal Leaf project. The page you're reading is rendered from
`content/getting-started/intro.md` with YAML front matter on top.

## What's here

- `config.yml` — site configuration (the `leaf:` section drives
  everything)
- `content/{section}/{slug}.md` — Markdown pages, organized by section
- `templates/` — optional Latte/PHP/HTML overrides of the bundled theme
- `public/` — static assets copied verbatim to `dist/` at build time

## Run it

```bash
leaf dev      # http://localhost:8080 with live reload
leaf build    # static HTML to dist/
```

If you don't have the `leaf` binary yet:

```bash
curl -fsSL https://leaf.ophelios.com/install.sh | sh
```
