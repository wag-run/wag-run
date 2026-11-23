---
paths:
  - '**'
---

# General

## Always add tests
Every change must include new or updated tests covering it. Run the affected tests before finishing.

## Consider README and docs updates for new features
This is an open source project (AGPL-3.0). For new or changed user-facing features, consider whether `README.md` or any files within `docs/` need updating (for example `docs/actions.md` for a new node, `docs/triggers.md` for a new trigger, `docs/editor.md` for editor changes). If unsure, stop and ask the user before proceeding.

## Split PRs into multiple commits if you're doing a lot
Especially if it's multiple features coming together into a single PR.
