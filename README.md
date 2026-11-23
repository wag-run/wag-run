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

A condition is one placeholder that is true or false, such as `{{ trigger.amount > 100 and 'vip' in trigger.tags }}`. Publishing refuses a condition without the braces, which would otherwise always count as true.

Placeholders use [Symfony ExpressionLanguage](https://symfony.com/doc/current/reference/formats/expression_language.html) with helper functions such as `upper`, `title`, `join`, `split`, `column`, `sum`, `default`, `date`, `number_format` and `json_decode`. A path into missing data, like `nodes.skipped.output.id`, reads as null instead of failing.

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

## Self-hosting with Docker

The image runs the web server, two queue workers and the scheduler in one container, next to a PostgreSQL container. You need Docker with the Compose plugin.

```bash
git clone https://github.com/wag-run/wag-run.git wag.run
cd wag.run
cp .env.docker.example .env
```

Set the one required value in `.env`, a password for the database:

```bash
echo "DB_PASSWORD=$(openssl rand -hex 24)"
```

Then build and start it:

```bash
docker compose up -d --build
```

On first start the container generates an `APP_KEY`, saves it in the storage volume and runs the database migrations. Open <http://localhost:8080> and register. **The first account becomes the organization admin**, so do this before exposing the install to anyone else. Migrations run on every start, so upgrading is `git pull` followed by the same `docker compose up -d --build`.

### Settings

All settings live in `.env`, next to `docker-compose.yml`.

| Setting | Default | What it does |
|---|---|---|
| `DB_PASSWORD` | required | Password for the bundled PostgreSQL database. |
| `APP_KEY` | generated | Encrypts sessions and stored secrets. Left empty, one is generated on first run and kept in the storage volume as `app.key`. Set it yourself to keep the key out of the volume. Never change it on a running install: secrets saved with the old key become unreadable. |
| `APP_URL` | `http://localhost:8080` | The public URL. Webhook and form trigger links are built from it. |
| `APP_BIND` | `127.0.0.1` | Host address the port is published on. Use `0.0.0.0` to expose it without a reverse proxy. |
| `APP_PORT` | `8080` | Host port. |
| `TRUSTED_PROXIES` | empty | IP addresses or CIDR ranges of your reverse proxy, comma-separated, or `*` if the app is reachable only through it. Needed for HTTPS links behind a proxy. |
| `SESSION_SECURE_COOKIE` | `false` | Set to `true` once the site is served over HTTPS. |
| `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS` | `log` mailer | SMTP settings. You can also set email up in the app under **Organization → Email**. |
| `QUEUE_WORKERS` | `2` | Number of queue workers running workflow steps. |
| `LOG_LEVEL` | `info` | Logs go to the container log (`docker compose logs -f app`). |
| `APP_MEMORY_LIMIT`, `DB_MEMORY_LIMIT` | `1g` | Memory limit per container. |

### HTTPS

The container serves plain HTTP on `127.0.0.1:8080`. Put a reverse proxy that terminates TLS (Caddy, Traefik, nginx) in front of it, then set `APP_URL` to the `https://` address, `TRUSTED_PROXIES` to the proxy's address and `SESSION_SECURE_COOKIE=true`.

### Data and backups

Two named volumes hold everything: `wag_database` (PostgreSQL) and `wag_storage` (uploaded files). Back up both. The generated `APP_KEY` is in the storage volume; if you set your own, back that up too.

```bash
docker compose exec db pg_dump -U wag wag > backup.sql
```

### How the containers are locked down

- Every process runs as an unprivileged user (`82:82` in the app, `70:70` in the database). Nothing starts as root and drops privileges later, so the stack also works under rootless Docker and Podman.
- All Linux capabilities are dropped and `no-new-privileges` is set.
- Root filesystems are read-only. The app writes only to its storage volume and a `noexec` tmpfs at `/tmp`; the application code is owned by root and not writable by the app.
- The database has no published port and sits on an internal network that only the app can reach.
- The image is a multi-stage build: Composer, Node, dev dependencies, tests and `.env` files never reach it. nginx executes `index.php` and nothing else.
- Containers have memory and process limits, and the port is published on loopback unless you change `APP_BIND`.

To run without PostgreSQL, the image falls back to SQLite stored in the storage volume when no `DB_*` settings are given. Build the image with `docker build -t wag.run .`, put only `APP_URL` in `.env`, then:

```bash
docker run -d --read-only --cap-drop ALL --security-opt no-new-privileges --tmpfs /tmp \
  --env-file .env \
  -v wag_storage:/var/www/html/storage -p 127.0.0.1:8080:8080 wag.run:latest
```

## Running in production without Docker

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
