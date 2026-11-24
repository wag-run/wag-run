# wag.run documentation

wag.run is a workflow automation app for teams. You build a workflow on a visual canvas by connecting a **trigger** (what starts it) to **actions** (what it does), publish it, and then watch every run step by step. When a workflow needs a person, such as an approval or a form to fill in, it pauses and puts a request in that person's inbox.

## Guides

| Page | What it covers |
|---|---|
| [Getting started](getting-started.md) | Signing in, finding your way around, and building, testing and publishing your first workflow |
| [The workflow editor](editor.md) | The canvas, connecting nodes, the settings panel, sticky notes, drafts and versions, workflow settings, import and export |
| [Triggers](triggers.md) | Manual, webhook, schedule, form and GitHub triggers |
| [Actions](actions.md) | Every action node: flow, data, integrations, AI, messaging and people |
| [Data and placeholders](data-and-placeholders.md) | Using earlier outputs, the data picker, `{{ }}` expressions and helper functions |
| [Chat webhooks](chat-webhooks.md) | Getting an incoming webhook from Slack, Mattermost, Microsoft Teams, Discord and Google Chat for the Chat message action |
| [Approvals, tasks and the Inbox](people.md) | Bringing people into a run, and responding to requests |
| [Runs](runs.md) | Test runs, the run page, statuses, retries, error paths, concurrency and log retention |
| [Workspaces](workspaces.md) | Members and roles, variables, secrets, credentials, workspace settings and the audit log |
| [API and MCP server](api.md) | Personal access tokens, the REST API, and connecting AI assistants such as Claude |
| [Organization administration](administration.md) | Users, workspaces, sign-in, sign-in providers, email and organization defaults |
| [Self-hosting](self-hosting.md) | Running wag.run with Docker: settings, HTTPS, backups and upgrades |
| [Local development](development.md) | Setting up a checkout to work on the code, including the AI agent skills |

## What you can do with it

- **Build visually.** Drag nodes onto a canvas and draw connections between them. Each node's outputs can be used by the nodes after it, through a data picker or `{{ }}` placeholders.
- **Start workflows many ways.** Run one by hand, call it from another system with a webhook or the API, run it on a schedule, let people start it by filling in a form, or start it on a new GitHub tag, release or failed Actions run.
- **Call other systems.** Make HTTP requests, work with GitHub issues, releases and Actions, add rows to Google Sheets, send email, post to Slack, Teams, Discord or Google Chat, send in-app notifications, call an LLM, or start another workflow.
- **Add logic.** Branch on a condition, switch on a value, loop over or filter a list, wait, merge parallel branches, or end a run early.
- **Bring people in.** Approvals pause a run until enough approvers say yes. Tasks pause it until someone fills in a form.
- **Change safely.** Edits stay in a draft until you publish. Every publish is a numbered version, and you can test a draft, discard it, or roll back to an older version.
- **See what happened.** Every run is recorded with each step's input, output and error, shown on the same canvas you built it on.

## Key concepts

### Organization

The top level, with one organization per wag.run install. Organization admins manage users, workspaces, sign-in options, email settings and organization-wide defaults. Everyone else is an organization **Member**, and their access comes from the workspaces they belong to.

### Workspace

Where the work happens. Each workspace has its own workflows, runs, variables, secrets, credentials and members. People are added to a workspace with one of four roles:

| Role | Can |
|---|---|
| **Admin** | Everything an Editor can, plus manage members, secrets, credentials and workspace settings, and read the audit log |
| **Editor** | Build, publish, run and test workflows, cancel runs, and manage variables |
| **Viewer** | See workflows, runs and members, and the names (not values) of variables, secrets and credentials |
| **Guest** | See only the approvals and tasks assigned to them |

Organization admins act as Admin in every workspace.

Switch between workspaces with the workspace menu at the top of the sidebar.

### Workflow

A graph of connected nodes. It starts at a **trigger** and moves along the connections to **actions**. Some nodes have more than one output, such as **Conditional logic** (true and false) or **Approval** (approved and rejected), and only the connections from the output that was taken run. Connecting one output to several nodes runs them in parallel.

Connections can't loop back on themselves. To repeat steps, use a **For loop** node.

### Draft and version

Changes you make in the editor save automatically to a **draft**. The draft does nothing until you **publish** it, which creates a new numbered version. Webhooks, forms, schedules and manual runs always use the published version. A run stays on the version it started with, even if you publish again while it is going.

### Run

One execution of a workflow. A run has a status:

| Status | Meaning |
|---|---|
| **Running** | Steps are being worked on |
| **Waiting** | Paused on a Wait, an approval, a task or another workflow |
| **Complete** | Finished |
| **Failed** | A step failed and nothing handled the error |
| **Cancelled** | Someone cancelled it, a newer run replaced it, or it was too many levels deep in a chain of workflows starting other workflows |
| **Skipped** | Not started, because the workflow allows one run at a time and another was in progress |

A **test run** works like a real run but uses the draft instead of the published version, so you can try changes before publishing.

### Data and placeholders

Every node can use data from the trigger and from the nodes before it. Fields that accept data show an **Insert data** picker, or you can type a placeholder yourself:

```text
Order {{ trigger.order_id }} shipped to {{ nodes.lookup.output.body.name }}
```

What a placeholder can read:

| Name | What it holds |
|---|---|
| `trigger` | What the trigger received, such as manual inputs, form values or a webhook body |
| `nodes.<id>.output` | The output of an earlier node. The node's settings panel shows the exact name under **Reference its output as**. |
| `vars.<key>` | A workspace variable |
| `secrets.<key>` | A workspace secret. Its value is masked in run logs. |
| `execution` | The run's `id`, `workflow` name, `trigger_type` and `started_at` |
| `item`, `loop` | Inside a For loop: the current item, and its `index` and `count` |
| `error` | On a node reached through an **on error** connection: what went wrong |

Placeholders can also do arithmetic, comparisons and simple transformations, such as `{{ upper(trigger.values.name) }}` or `{{ trigger.values.price * trigger.values.quantity }}`. A field that holds only one placeholder keeps the value's type, so `{{ nodes.fetch.output.body.items }}` stays a list instead of turning into text.

A path to data that doesn't exist, such as the output of a branch that didn't run, reads as empty rather than failing the run.

### People in the loop

**Approval** and **Task** nodes pause a run and create a request for a named person. The request appears in their **Inbox** and, unless it's turned off on the node, arrives by email too. When they respond, the run carries on. If nobody responds before the node's timeout (48 hours by default), the step fails.

The **Inbox**, in the account menu at the bottom of the sidebar, is where everything for you lands, across all your workspaces: approvals and tasks, failed runs of workflows you own, and messages that workflows send you. A red dot on your avatar means something in it is unread.

### Variables, secrets and credentials

A workspace keeps shared values its workflows use:

- **Variables**: named values any workflow can read, such as a team email address or a base URL.
- **Secrets**: sensitive values, encrypted at rest and masked in run logs.
- **Credentials**: reusable sign-in details for other systems, such as HTTP Basic, an API token, an AI provider key, an SMTP server or a chat webhook, which nodes pick from a list.
