# wag.run

A self-hosted workflow automation app for teams. Build workflows on a visual canvas from triggers and actions, publish them as versions, and follow every run step-by-step. People participate in runs through approvals and tasks that land in their inbox.

Built with Laravel 13, Livewire 4, Flux and [WireFlow](https://github.com/getartisanflow/wireflow) for the canvas.

## Features

- **Visual editor**: drag nodes from the palette, draw connections, right-click to copy, paste or delete, undo and redo (⌘Z / ⇧⌘Z), and a data picker to insert earlier outputs into fields.
- **Drafts and versions**: edits stay in a draft until you publish. Each publish is a version, runs stay on the version they started with, and you can roll back or discard the draft.
- **Triggers**: manual (with inputs to fill in), webhook (optionally verified by token, HMAC signature or IP allowlist), schedule (interval or cron) and public or members-only forms.
- **Actions**: HTTP call, conditional logic, switch, for loop, wait, merge, terminate, compose, trigger another workflow, LLM call, send email and send notification.
- **People in the loop**: approvals (one or more approvers, with an "approvals needed" count) and tasks with a form to fill in. They show up in the assignee's inbox and, unless turned off on the node, by email.
- **Error handling**: per-node retries with backoff, and an optional "on error" path to catch a node that still fails.
- **Runs**: a live view of each run on the canvas with every step's input, output and error. Retention is configurable per organization, workspace and workflow.
- **Workspaces and roles**: Admin, Editor, Viewer and Guest roles per workspace, plus organization admins. Variables, encrypted secrets and reusable authentication settings (HTTP basic, API token, AI provider, SMTP) belong to a workspace.
- **Sign-in**: email and password, passkeys, two-factor authentication, and OpenID Connect providers.
- **Audit log** of changes to workflows, secrets, members and settings.

## Concepts

- **Organization**: one per install. Organization admins manage users, workspaces, sign-in providers, email (SMTP) and defaults.
- **Workspace**: where workflows, runs and settings live. People are added to a workspace with a role.
- **Workflow**: a graph of nodes. The first node is a trigger, and each node's outputs can be used by the nodes after it.
- **Run**: one execution of a published version. Runs can start other runs; a chain of runs starting runs stops at the organization's maximum chain depth (10 by default).

### Templates and expressions

Fields that accept data use `{{ }}` placeholders, such as `Order {{ trigger.id }} shipped`. A field that is a single placeholder keeps the value's type, so `{{ nodes.fetch.output.body.items }}` is a list, not text.

Conditions and Compose's expression mode take one expression without braces, such as `trigger.amount > 100 and 'vip' in trigger.tags`.

Both use [Symfony ExpressionLanguage](https://symfony.com/doc/current/reference/formats/expression_language.html) with helper functions such as `upper`, `title`, `join`, `split`, `column`, `sum`, `default`, `date`, `number_format` and `json_decode`. A path into missing data, like `nodes.skipped.output.id`, reads as null instead of failing.

## Getting started

Requirements: PHP 8.4, Composer, Node.js and npm. SQLite works out of the box.

```bash
git clone <repository-url> wag.run
cd wag.run
composer run setup
php artisan db:seed
composer run dev
```

`composer run setup` installs dependencies, creates `.env` and an app key, runs migrations and builds the frontend. `composer run dev` starts the web server, a queue listener for the `workflows` and `default` queues, the scheduler, the log viewer and Vite.

The seeder creates a Default workspace, four demo workflows (refund approval, order status updates, new hire onboarding and a post digest) and two accounts, both with the password `password`:

| Email | Role |
|---|---|
| `test@example.com` | Organization admin |
| `editor@example.com` | Member, Editor in the Default workspace |

Email is written to the log (`MAIL_MAILER=log`) until you set up SMTP under **Organization → Email**.

## Running in production

- **Queue worker**: workflow steps run on the `workflows` queue. Run a worker for it, and restart workers after each deploy:

  ```bash
  php artisan queue:work --queue=workflows,default
  ```

- **Scheduler**: run `php artisan schedule:run` every minute. It drives schedule triggers, wakes up waiting steps and times out approvals (`workflows:sweep`, `workflows:run-schedules`), and purges old run logs (`workflows:purge-executions`).
- **`APP_URL`** must be the public URL, because webhook and form trigger URLs are built from it.
- **Email**: configure SMTP under **Organization → Email**, or with the `MAIL_*` settings in `.env`.

## Extending

Nodes are PHP classes that extend `App\Engine\Node`: they declare their inputs, outputs and ports, and do their work in `execute()`. Register them with the `NodeRegistry`, the way `App\Providers\WorkflowServiceProvider` registers the built-in nodes in `app/Engine/Nodes`. The editor, data picker and validation pick them up automatically.

Authentication setting types extend `App\Credentials\CredentialType` and are registered with the `CredentialTypeRegistry` in `App\Providers\AppServiceProvider`.

## Development

```bash
php artisan test --compact   # the test suite
vendor/bin/pint              # code style
composer run types:check     # static analysis (Larastan)
```

Where things are:

| Path | What |
|---|---|
| `app/Engine` | The engine: coordinator, step runner, graph, templates, editor helpers and nodes |
| `app/Services` | Workflows, assignments, invitations, mail and workspace management |
| `resources/views/pages` | Livewire single-file page components |
| `database/seeders/DemoWorkflowSeeder.php` | The demo workflows |

## License

wag.run is licensed under the [GNU Affero General Public License v3.0](LICENSE).
