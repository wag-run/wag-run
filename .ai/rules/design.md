---
paths:
  - 'resources/views/**'
  - 'resources/css/**'
  - 'resources/js/**'
  - 'public/*.svg'
  - 'public/*.png'
---

# Design

## Read the design brief first
Before adding a screen, component, canvas element or icon, or changing how anything looks, read the `wag-design` skill (`.ai/skills/wag-design/SKILL.md`). It covers the logo, colour, type, components, layout and wording.

## Keep the interface greyscale
Greys are `zinc-*` (remapped to neutral), and the accent is black in light mode and white in dark mode. Use colour only for what it means: red failed or destructive, amber waiting or needs attention, green done, sky in progress. The logo's navy and tan never appear in the UI.

## Don't add new UI components unless absolutely necessary
Build from what exists: Flux components, the app's `x-` components and partials, `x-page-heading` for page titles and `$confirm()` for confirmations (never the browser's `confirm()`). Add a new component or variant only when nothing existing can do the job, and say why in the change. Every colour utility gets a `dark:` pair.

## Leave the logo alone
Don't edit or recolour the logo SVGs by hand. The product name is "wag.run", lowercase, and the UI reads it from `config('app.name')`.
