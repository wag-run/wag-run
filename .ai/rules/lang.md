---
paths:
  - 'lang/**'
  - 'resources/views/**'
---

# Lang

## Never hardcode user-facing text
Language strings live in `lang/en/*.php`, one file per area (`workflows.php`, `runs.php`, `settings.php`, ...). Reference them with `__('area.key')` in views, components and PHP; don't put literal UI text in Blade. Shared labels (Cancel, Save, Actions, ...) belong in `common.php`; check it before adding a duplicate.

## Key naming
Keys are snake_case and derived from the English text (`'no_matching_nodes' => 'No nodes match your search.'`). Page-specific strings are nested under the page's key, e.g. `workflows.edit.search_nodes`.

## Keep all translations in sync
Only `en` exists today. If another locale is added, update every locale file whenever you add or change a string, not just `en`.
