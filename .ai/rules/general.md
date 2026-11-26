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

## Limit mentions of "organizations" in the docs
Organizations exist primarily for the forthcoming SaaS hosted version and are not intended for use by self-hosters (at most would satisfy a curiosity of how wag.run is built). Minimise references to the organization construct in `README.md`, the self-hosting docs, and any other docs; describe features from a single self-hosted user's point of view instead. You can and should refer to "organizations" when referring to the settings applicable on those screens.

## Check the Claude docs for intent and direction
The developers' working notes live in two Claude docs, read and edited through the Claude Docs connector: the PRD ("Workflow Builder PRD") and the open items list ("Open items", whose other tabs hold design plans and background). They are private to the developer, so the connector or the docs may be unavailable to you; if so, continue without them. Read the relevant doc before making a big architectural change, when you are stuck and need guidance, or when you need to know what the developers intended. These are distinct from `docs/`, which is the published user documentation, and from the gitignored `.docs/` folder, which holds other static files that should be kept private.

These notes are guidance, not a substitute for the user. If you have a real concern (the notes conflict with the code or the request, are silent on the question, or the change is risky), stop and ask instead of settling it from the Claude docs alone.

## Always keep the Claude docs updated
When you can reach the Claude docs, keep them current as part of the change: mark items in "Open items" as done or add new ones your work uncovers, and update "Workflow Builder PRD" when a decision, scope, or behaviour it describes changes. The developers edit these docs too, so read the current version before you edit. If you cannot reach them, skip this and tell the user what needs updating; do not recreate the notes anywhere else in the repository.

## Split PRs into multiple commits if you're doing a lot
Especially if it's multiple features coming together into a single PR.
