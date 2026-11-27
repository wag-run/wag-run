---
name: wag-design
description: "wag.run's design brief: the logo and how to use it, brand colours, and the philosophy for how the UI is pieced together (colour, type, components, layout, dark mode and words). Use before adding or restyling any page, component, canvas element, email or icon, before touching the logo files, and whenever a choice of colour, component or wording is not already settled by a neighbouring view."
---

# wag.run design brief

wag.run is a self-hosted tool that people trust to run their work. It should look and read like one: calm, plain and precise. The interface steps back so the workflows, the runs and the people in them come forward.

This brief is for coding agents and for people contributing to the project. It describes how the app already looks, so new work fits in. When a neighbouring view and this brief disagree, follow the brief and mention the difference.

## Principles

1. **Greyscale by default.** The interface is black, white and neutral greys. Colour is reserved for meaning, so when something is red, amber or green, it matters.
2. **Content first.** Workflow names, run data, expressions and people's tasks are the content. Chrome (borders, backgrounds, headings) stays quiet around them.
3. **Plain words.** Say what a thing is and what will happen. No jargon the person didn't bring, no jokes, no exclamation marks.
4. **No new components unless there's no other way.** Build from what the app already has: Flux's components, then the app's own (`x-page-heading`, the confirm dialog, the data picker, the `wag-` classes). A new component, or a new variant of an existing one, is a last resort, for a job nothing existing can do. Two different-looking dialogs for the same job is a bug.
5. **Light and dark are equals.** Every screen works in both. Nothing is designed in one mode and patched in the other.
6. **Quiet character.** The dog in the logo is the one playful touch. The rest of the product doesn't need to wink.

## Logo

### The mark

A dog standing on a flow line that ends in an arrow. The dog's body and legs form a **W**, and the line underneath is a workflow running forward: **wag** + **run**.

| File | What it is | Use it for |
|---|---|---|
| `public/logo.svg` | The mark on a light grey (`#e6e6e6`) rounded tile, filled with the brand gradient | The README, docs, social previews, anywhere the mark stands alone at size |
| `public/apple-touch-icon.png` | The tile as a 180×180 PNG | Home-screen icons. Regenerate from `logo.svg` when the mark changes |
| `public/favicon.svg` | The mark alone, flat: `#262626` on light tabs, white on dark tabs, switched by a `prefers-color-scheme` rule inside the SVG | The browser tab |
| `x-app-logo-wordmark` (`resources/views/components/app-logo-wordmark.blade.php`) | The dog with **wag** in lowercase, on the flow line, in `currentColor`. "wag" is the short form on purpose: the flow line's arrow stands for "run" | App chrome, through `x-app-logo` (sidebar and header brand) |
| `x-app-logo-mark` (`resources/views/components/app-logo-mark.blade.php`) | The mark alone, from `favicon.svg`, in `currentColor` | The collapsed sidebar, through `x-app-logo`, where the wordmark doesn't fit |

Rules:

- Don't redraw, stretch, rotate, outline or add effects to the mark. Change the source SVG and regenerate the PNG instead.
- In the app, the wordmark is one colour: black in light mode, white in dark mode (`text-black dark:text-white`). Never put the gradient on the wordmark.
- The gradient belongs to the tile version of the mark only.
- Logo SVGs keep a `<title>` and `role="img"`. The wordmark's title comes from `config('app.name')`.
- **Clear space:** leave at least the height of the dog's head free on every side of the mark or wordmark. Nothing else (text, buttons, edges) goes inside it.
- **Minimum size:** the mark no smaller than 16px, as in the favicon; the wordmark no smaller than 24px tall. In the app chrome it's 32px (`h-8`).

### The name

The product is **wag.run**: lowercase, with the dot, even at the start of a sentence. The wordmark's "wag" is a graphic short form only; in text, always write the full name. In the UI, take it from `config('app.name')` so self-hosters can rename their install. Don't write "Wag", "WAG" or "Wag.run".

## Colour

### Brand colours

| Name | Value | Where |
|---|---|---|
| Navy | `#00324e` | Start of the logo gradient |
| Tan | `#ab977d` | End of the logo gradient |
| Tile grey | `#e6e6e6` | Behind the mark on the tile |

These stay in the logo and outward-facing material (README, social images). They are **not** used in the app's interface.

### Interface colours

- **Greys.** Tailwind's `zinc` scale is remapped to neutral greys in `resources/css/app.css`, so `zinc-*` has no blue tint. Use `zinc-*` for every grey: text, borders, backgrounds.
- **Accent.** `--color-accent` is near-black (`neutral-800`) in light mode and white in dark mode. Flux's primary buttons, focus and selection use it. Don't add a coloured accent.
- **Surfaces.** The page is white (`zinc-900` dark). The sidebar is `zinc-50` (`zinc-900` dark) with the `.wag-noise` film-grain texture, the one decorative surface in the app.

### Colours that carry meaning

Use a colour only for its meaning below, and always with its dark-mode pair (usually the `-500/20` tint for backgrounds and the `-300` shade for text).

| Colour | Means | Examples |
|---|---|---|
| Red | Failed, destructive, invalid | Failed runs, danger buttons and menu items, validation errors, unknown nodes |
| Amber | Waiting or needs someone's attention; triggers | Waiting steps, "Action needed" in the inbox, revealed secrets in the audit log, trigger node icons |
| Green | Done, succeeded | Completed steps, finished tasks |
| Sky | In progress | Running and pending steps |
| Violet, sky, emerald | Expression syntax only | `{{ }}` placeholders, function names, quoted text in templated fields |

Sticky notes on the canvas have their own palette (yellow, blue, green, pink, grey) because the colour is the person's choice, not the app's.

If a new state doesn't fit a row above, use grey and ask before adding a colour.

## Type

- **Instrument Sans** for everything, loaded through `@fonts`.
- **Mono** (`font-mono`) for anything a person might copy or type exactly: IDs, keys, expressions, templated fields, cron expressions, JSON, type labels in the data picker.
- Headings come from `flux:heading` (page titles are `size="xl" level="1"` through `x-page-heading`), body text from `flux:text`. Don't set font sizes by hand where a Flux component already does.
- Small supporting text is `text-xs` in `zinc-500` (`zinc-400` dark).

## Building the UI

### Components

- **Flux first.** Buttons, inputs, selects, menus, modals, tables, badges and callouts come from Flux. Read the `fluxui-development` skill before reaching for raw HTML.
- **Before adding a component**, look for one that already does the job: a Flux component, an `x-` component in `resources/views/components`, a partial in `resources/views/partials` or a `wag-` class in `resources/css/app.css`. Compose or extend it instead. Add a new one only when nothing existing can do the job without contortion, and say why in the change. When one is truly needed, build it from Flux parts and the existing colours, type and spacing, so it looks like it was always there.
- **Page headings** use `x-page-heading`, with actions in its `actions` slot.
- **Lists of records** are `flux:table`. An empty table shows one centred row in `zinc-500`, such as "No workflows yet."
- **Confirmation** goes through the app's one dialog, `$confirm({ title, message, confirm, danger })` from Alpine. Never the browser's `confirm()`.
- **Modals** are `flux:modal` at `md:w-md`, `md:w-lg` or `md:w-xl`, with a `flux:heading size="lg"` title and actions bottom-right: ghost Cancel, then the primary (or danger) action.
- **Badges** show status, in the colour its meaning calls for (`zinc` when it has none).
- **Icons** are Heroicons through Flux's `icon` attributes and `flux:icon.*`. Use them to help recognition next to a label, not instead of one. An icon-only button needs an `aria-label`.

### Buttons

| Variant | Use |
|---|---|
| `primary` | The main action of a page, form or dialog. One per view where possible. |
| `ghost` | Secondary and cancel actions, row actions |
| `subtle` | Small helpers beside a field, such as **Insert data** |
| `danger` | Deleting or removing something that can't be undone |
| `outline`, `filled` | Rarely; follow what the neighbouring view does |

Sizes: `sm` is the everyday size in panels, tables and forms. `xs` is for helpers attached to a field. `lg` and `xl` are for the few large moments, such as auth screens.

### Layout and spacing

- Pages are a sidebar plus a main area with an `x-page-heading` on top. The workflow editor is the exception: a full-height palette, canvas and settings panel.
- Space with Tailwind's scale and `gap-*`/`space-y-*`, not one-off margins. Forms and modal bodies use `space-y-6`; tight groups use `gap-2`.
- Corners: `rounded-lg` for panels and cards, `rounded-md` for small elements inside them, `rounded-full` only for pills and avatars.
- Borders are `zinc-200` (`zinc-700` dark). Shadows are for things that float (menus, popovers, dragged nodes), not for flat panels.

### The canvas

Canvas nodes (`.wag-node`) are 220px wide cards with an icon tile, the node name and its type and ID in `text-xs`. A run's state shows as a pill on the node in the meaning colours above, and nodes that didn't run fade to half opacity. Keep new canvas styling in `resources/css/app.css` next to the existing `wag-` classes.

### Custom CSS

Prefer Tailwind utilities in the view. When a style needs CSS (the canvas, the expression highlight, anything Flux or WireFlow renders), add a `wag-` prefixed class in `resources/css/app.css` with a one-line comment saying what it styles.

### Dark mode

Every colour utility has a `dark:` pair, and every new screen is checked in both modes. People switch between Light, Dark and System under **Preferences › Appearance**.

### Illustration

Illustration is rare and comes from the mark: sparse line drawings of the dog and the flow line, in one colour (`currentColor`, usually `zinc-400`/`zinc-500`), with no gradient, fills or new characters. Use one only where it helps a moment people meet once, such as an empty workspace, onboarding or a docs page, and never more than one per screen. It sits beside the words, never instead of them: the empty state still says what's missing and how to get it.

### Motion

Motion explains a change, such as a dot travelling along a connection into a running step. It never decorates. Keep transitions short and leave everything usable without them.

## Words

- All UI text lives in `lang/en/*.php` (see `.ai/rules/lang.md`).
- **Sentence case** for everything: buttons, headings, menu items, labels. "Insert data", not "Insert Data".
- **Buttons say what happens**: "Publish", "Add row", "Delete node". Not "OK" or "Submit".
- **Errors say what went wrong and what to do**, in the person's terms. "upper() needs 1 argument, but got 0." Never a PHP message, class name or server path.
- **Empty states** say what's missing and, where it helps, how to get it. "No runs yet, so data reads as empty. Run the workflow once to preview it with real data."
- **Destructive confirmations** name the thing and the consequence. "Delete this node? Its connections will be deleted too."
- In the docs, names of things on screen are **bold**, and paths through the UI use › (**Preferences › Appearance**).

## Accessibility

- Anything clickable shows the pointer; `app.css` handles this for buttons, links, menu items and `wire:click`/`x-on:click` elements.
- Icon-only controls have an `aria-label`. Decorative layers have `aria-hidden="true"`.
- Meaning is never carried by colour alone: a status pill has a word in it, an invalid field has a message.
- Keep text people need to read at WCAG AA contrast in both modes: `zinc-500` or darker on white, `zinc-400` or lighter on dark surfaces. Lighter greys on white are for incidental labels only, such as a type hint beside a value.

## When the brief doesn't cover it

If a choice isn't settled here or by a neighbouring view, such as a new colour, a new kind of component or a new logo use, ask before inventing one, then add the answer to this brief.
