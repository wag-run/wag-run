---
paths:
  - '**'
---

# General

## Always add tests
Every change must include new or updated tests covering it. Run the affected tests before finishing.

## Consider README and docs updates for new features
This is an open source project (AGPL-3.0). For new or changed user-facing features, consider whether `README.md` or any files within `docs/` need updating (for example `docs/actions.md` for a new node, `docs/triggers.md` for a new trigger, `docs/editor.md` for editor changes). If unsure, stop and ask the user before proceeding.

## Always use the Oxford comma
Strictly enforce the Oxford (serial) comma in docs and in UI text strings: write "nodes, triggers, and credentials", never "nodes, triggers and credentials". Fix any list of three or more items that lacks it in text you touch.

## Keep "organizations" out of the docs
Organizations exist for the forthcoming SaaS hosted version and are not really intended for self-hosters. Minimise any reference to the organization ("org") level in `README.md`, the self-hosting docs, and any other docs; describe features from a single self-hosted install's point of view instead.

## Check `.docs/` for intent and direction
The `.docs/` folder holds the developers' working notes: the PRD (`Workflow Builder PRD.md`), the open items list (`Open Items.md`), and other helpful background. It is gitignored, so it may be missing in a fresh clone; if so, continue without it. Read the relevant files there before making a big architectural change, when you are stuck and need guidance, or when you need to know what the developers intended. This is distinct from `docs/`, which is the published user documentation.

These notes are guidance, not a substitute for the user. If you have a real concern (the notes conflict with the code or the request, are silent on the question, or the change is risky), stop and ask instead of settling it from `.docs/` alone.

## Always keep the `.docs/` directory updated
When `.docs/` is present in the project folder, keep it current as part of the change: mark items in `Open Items.md` as done or add new ones your work uncovers, and update the PRD when a decision, scope, or behaviour it describes changes. If the folder is missing, skip this; do not create it.

## Split PRs into multiple commits if you're doing a lot
Especially if it's multiple features coming together into a single PR.
