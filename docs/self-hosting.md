# Self-hosting

wag.run ships as a Docker image, `ghcr.io/wag-run/wag-run`, built for both x86 (amd64) and ARM (arm64) servers. The same image is on Docker Hub as `wagrun/wag-run`. One container runs the web server, the queue workers that run workflow steps, and the scheduler. A second container runs PostgreSQL. Both are locked down by default: they run as unprivileged users, with read-only filesystems and no Linux capabilities.

## Requirements

- Docker with the Compose plugin. Rootless Docker and Podman work too.
- About 1 GB of memory for each container. You can change the limits in the settings.
- A domain name and a reverse proxy for HTTPS if people will reach the install over the internet.

## Install

1. Make a folder for the install, then download the Compose file and the settings file into it:

   ```bash
   mkdir wag.run && cd wag.run
   ```

   ```bash
   curl -fsSLO https://raw.githubusercontent.com/wag-run/wag-run/main/docker-compose.yml
   ```

   ```bash
   curl -fsSL https://raw.githubusercontent.com/wag-run/wag-run/main/.env.docker.example -o .env
   ```

2. Generate a database password:

   ```bash
   echo "DB_PASSWORD=$(openssl rand -hex 24)"
   ```

   Copy the line it prints into `.env`, replacing the empty `DB_PASSWORD=`. This is the only setting you must fill in: until it is set, `docker compose up` stops with "Set DB_PASSWORD in .env".

3. Start the containers:

   ```bash
   docker compose up -d
   ```

   On first start, the app generates an encryption key (`APP_KEY`), saves it in its storage volume, and creates the database tables.

4. Open <http://localhost:8080> and **register**. The first account becomes the organization admin, so do this before anyone else can reach the install.

5. As the admin, set up [email](administration.md#email) so invitations and approval requests reach people, then create a [workspace](administration.md#workspaces) and [invite your team](administration.md#users).

## Settings

All settings live in `.env`, next to `docker-compose.yml`. After changing one, apply it with:

```bash
docker compose up -d
```

| Setting | Default | What it does |
|---|---|---|
| `DB_PASSWORD` | required | Password for the bundled PostgreSQL database. Compose won't start without it. |
| `WAG_VERSION` | `latest` | The release to run. `latest` follows every release, a minor version such as `1.2` gets only its patch updates, and an exact version such as `1.2.3` stays on it. See [Upgrading](#upgrading). |
| `APP_KEY` | generated | Encrypts sessions and stored secrets. Left empty, one is generated on first run and kept in the storage volume as `app.key`. See [The encryption key](#the-encryption-key). |
| `APP_URL` | `http://localhost:8080` | The address people use to reach wag.run. Webhook and form trigger links are built from it, so set it to your real address. |
| `APP_BIND` | `127.0.0.1` | The host address the port is published on. Keep it when a reverse proxy on the same machine forwards to the app. Use `0.0.0.0` to expose the port directly. |
| `APP_PORT` | `8080` | The host port |
| `TRUSTED_PROXIES` | empty | Your reverse proxy's IP addresses or CIDR ranges, comma-separated, or `*` if the app can only be reached through the proxy |
| `SESSION_SECURE_COOKIE` | `false` | Set to `true` once the site is served over HTTPS |
| `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS` | `log` | SMTP settings for outgoing email. You can set email up in the app instead, under **Organization → Email**. |
| `MCP_SERVER_ENABLED` | `true` | Set to `false` to turn off the [MCP server](api.md) for AI assistants, and the OAuth routes they use to connect. The REST API keeps working. |
| `GITHUB_CLIENT_ID`, `GITHUB_CLIENT_SECRET` | empty | Optional. Your GitHub OAuth app, so people can connect GitHub accounts in one click. See [Connecting GitHub and Google](#connecting-github-and-google). |
| `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` | empty | Your Google OAuth client, so workspaces can connect Google accounts |
| `APP_NAME` | `wag.run` | The name shown in the app and in emails |
| `QUEUE_WORKERS` | `2` | How many workers run workflow steps at once |
| `LOG_LEVEL` | `info` | How much the app logs |
| `WORKFLOW_FILES_MAX_MB` | `50` | The largest file, in MB, that a run can receive, download, or have uploaded to it. The image accepts requests up to 55 MB, so you can lower this but not raise it. |
| `APP_MEMORY_LIMIT`, `DB_MEMORY_LIMIT` | `1g` | Memory limit for each container |

Until email is set up, outgoing mail is written to the log instead of sent.

## Connecting GitHub and Google

Workspaces connect GitHub and Google accounts through OAuth, for the [GitHub](triggers.md#github) and [Google](actions.md#google) nodes. An OAuth app is tied to one install, because the provider sends people back to that install's address. Google needs one before anyone can connect: until it's set up, the **Add credential** form and the Google nodes say which settings are missing instead of showing a **Connect** button. For GitHub, it's optional (see below).

The provider only sends people's browsers back to wag.run, so connecting works even when the install isn't reachable from the internet, such as on a private network.

### GitHub

Setting up an install-wide GitHub **OAuth App** lets anyone in a workspace connect their GitHub account in one click, without any GitHub details of their own. It's optional: workspaces can also use a personal access token, a GitHub App, or a GitHub setting with its own OAuth app. See [Other ways to connect GitHub](workspaces.md#other-ways-to-connect-github).

1. Open the form for a new OAuth App. Create it under your GitHub organization, so it doesn't depend on one person's account: the organization's **Settings → Developer settings → OAuth Apps → New OAuth App**. Or, under your own account, your avatar → **Settings → Developer settings → OAuth Apps → New OAuth App**.
2. Fill it in:

   | Field | Value |
   |---|---|
   | **Application name** | Something people will recognize on GitHub's consent page, such as `wag.run` |
   | **Homepage URL** | Your `APP_URL`, such as `https://wag.example.com` |
   | **Authorization callback URL** | `APP_URL/integrations/oauth/github/callback`, such as `https://wag.example.com/integrations/oauth/github/callback` |
   | **Enable Device Flow** | Leave it off |

   The callback URL must match the address people use, including `https`.
3. Choose **Register application**. Copy the **Client ID**, then choose **Generate a new client secret** and copy the secret right away: GitHub shows it only once.
4. Set them in `.env`:

   ```bash
   GITHUB_CLIENT_ID=your-client-id
   GITHUB_CLIENT_SECRET=your-client-secret
   ```

5. Apply the settings with `docker compose up -d`.
6. In a workspace, open **Credentials → Add credential**, choose **GitHub**, then **Connect GitHub**. Or select a GitHub node in the editor and choose **Connect GitHub** under its **GitHub connection** field. GitHub asks you to approve access to your repositories and their webhooks. Choose **Authorize**, and you're sent back to where you started. See [Connecting an account](workspaces.md#connecting-an-account).

If your organization restricts which OAuth Apps can see its repositories, GitHub's consent page lists it under **Organization access**. Choose **Grant**, or **Request** to ask an organization owner. Otherwise the connection can't see the organization's repositories, and the GitHub nodes fail with `404`.

The connection acts as the account that approved it, with access to every repository that account can see. Consider connecting a bot account instead of your own.

Without this app, the **GitHub** form asks for the client ID and secret of an app of the workspace's own, and a GitHub node with no setting chosen points to the **Credentials** page.

#### Polling or webhooks

GitHub triggers can check for new events every few minutes, or register a webhook on the repository. Polling works anywhere. A webhook only works if GitHub can reach your `APP_URL`, so it doesn't work for an install on a private network, or for one on your own computer without a tunnel such as Cloudflare Tunnel or ngrok.

The webhook is added to the repository, so you'll find it under the repository's **Settings → Webhooks**, not the organization's. Its **Recent Deliveries** show whether GitHub reached wag.run. If `APP_URL` changes, webhooks registered before keep the old address until the trigger's settings change and the workflow is published again.

### Google

1. In the [Google Cloud console](https://console.cloud.google.com), create or pick a project, then under **APIs & Services → Library** enable the **Google Sheets API**.
2. Set up the **OAuth consent screen**. Choose **Internal** if everyone who'll connect an account is in your Google Workspace organization, otherwise **External**. Add the `.../auth/spreadsheets` scope.
3. Under **APIs & Services → Credentials**, choose **Create credentials → OAuth client ID**, of type **Web application**. Add `APP_URL/integrations/oauth/google/callback`, such as `https://wag.example.com/integrations/oauth/google/callback`, as an **Authorized redirect URI**.
4. Copy the client ID and secret into `.env`, then apply them with `docker compose up -d`:

   ```bash
   GOOGLE_CLIENT_ID=your-client-id
   GOOGLE_CLIENT_SECRET=your-client-secret
   ```

5. Connect a Google account the same way as for GitHub, with **Connect Google**.

An **External** app in the **Testing** publishing status only lets the test users you list connect, and Google expires their connections after 7 days. To keep connections working, publish the app or use an **Internal** one.

## HTTPS

The app container serves plain HTTP. Put a reverse proxy that handles TLS, such as Caddy, Traefik, or nginx, in front of it, forwarding to `127.0.0.1:8080`. Then set these in `.env`:

```dotenv
APP_URL=https://wag.example.com
TRUSTED_PROXIES=127.0.0.1
SESSION_SECURE_COOKIE=true
```

Set `TRUSTED_PROXIES` to the address your proxy connects from. Without it, the app doesn't trust the proxy's forwarded headers and builds `http://` links.

Requests to the app, such as webhook calls and form uploads, can be up to 55 MB, and each file in them up to 50 MB (see `WORKFLOW_FILES_MAX_MB`). If your proxy limits request size, as nginx does at 1 MB by default, raise its limit to match, such as `client_max_body_size 55m;`.

## The encryption key

`APP_KEY` encrypts sessions and everything stored as secret: workspace secrets, credentials, and the organization's SMTP password.

- If you leave it empty, the generated key is kept in the storage volume, so a backup of that volume includes it.
- If you'd rather keep the key out of the volume, generate one and put it in `.env` before the first start:

  ```bash
  echo "APP_KEY=base64:$(openssl rand -base64 32)"
  ```

**Never change the key on an install that is in use.** Secrets saved with the old key can't be read with a new one.

## Data and backups

Two Docker volumes hold everything:

| Volume | What's in it |
|---|---|
| `wag_database` | The PostgreSQL database: workflows, runs, users, and settings |
| `wag_storage` | The app's own files, including the generated `APP_KEY` and the keys that sign API tokens (`oauth-private.key` and `oauth-public.key`) |

Back up both. To dump the database:

```bash
docker compose exec db pg_dump -U wag wag > backup.sql
```

If you set `APP_KEY` yourself, keep a copy of it with your backups. If the API token keys are lost, new ones are generated on the next start, and everyone's API tokens and connected AI assistants stop working until they're created or connected again.

## Upgrading

Download the new image and restart on it:

```bash
docker compose pull && docker compose up -d
```

This moves to the newest release that `WAG_VERSION` allows. To move to a newer minor version when you've pinned one, change `WAG_VERSION` first. Read the [release notes](https://github.com/wag-run/wag-run/releases) before a big jump.

To try changes before they're released, set `WAG_VERSION=nightly`. That image is rebuilt every night from the `main` branch, and each night's build is also kept under a dated tag such as `nightly-20261124`. Nightly builds are previews that haven't been through a release: don't run one on an install you depend on, and back up before you switch, because a database change made by a nightly can't be undone by going back to a release.

Database changes are applied automatically every time the app starts. When the container stops, workers get about a minute to finish the step they're on. Steps that were queued but not started run once the app is back. A step that was cut off mid-run is marked failed with "The worker running this step stopped responding", and is retried if its node has retries set.

## Logs and health

Everything the app logs goes to the container log:

```bash
docker compose logs -f app
```

The app answers `GET /up` with a 200 when it's healthy, and the container's built-in health check uses it. Point an uptime monitor at the same address.

To run a one-off command inside the app, such as clearing the cache:

```bash
docker compose run --rm app php artisan optimize:clear
```

## Running without PostgreSQL

For a small or trial install, the image can store its data in SQLite inside the storage volume. It does this when no `DB_*` settings are given. Put only `APP_URL` in `.env`, and start the image on its own:

```bash
docker run -d --read-only --cap-drop ALL --security-opt no-new-privileges --tmpfs /tmp --env-file .env -v wag_storage:/var/www/html/storage -p 127.0.0.1:8080:8080 ghcr.io/wag-run/wag-run:latest
```

Back up the `wag_storage` volume, which then holds the database too.

## Building the image yourself

To run your own changes, build the image from a checkout under a tag of your own, then point `WAG_VERSION` at that tag in `.env`:

```bash
docker build -t ghcr.io/wag-run/wag-run:local .
```

```bash
echo "WAG_VERSION=local" >> .env && docker compose up -d
```

Skip `docker compose pull` while you run it, or set `WAG_VERSION` back to a release to return to the published images.

## How the containers are locked down

- Every process runs as an unprivileged user. Nothing starts as root, and the app refuses to start if you override it to run as root.
- All Linux capabilities are dropped, and processes can't gain new privileges.
- Root filesystems are read-only. The app writes only to its storage volume and to a `/tmp` that can't run programs. The application code is owned by root and the app can't change it.
- The database has no published port. It sits on an internal network that only the app can reach and that has no route to the internet.
- The image contains only what runs the app: no build tools, development dependencies, tests, or `.env` files.
- Each container has memory and process limits, and the app's port is published only on the local machine unless you change `APP_BIND`.

## Running without Docker

wag.run is a standard Laravel application, so you can run it on your own PHP 8.4 server. Besides the web server, it needs:

- **A queue worker** for the `workflows` and `default` queues, which runs workflow steps. Restart it after every upgrade.

  ```bash
  php artisan queue:work --queue=workflows,default
  ```

- **The scheduler**, run every minute. It starts scheduled workflows, wakes up waiting steps, times out approvals and tasks, and removes run logs past their retention period.

  ```bash
  php artisan schedule:run
  ```

- **`APP_URL`** set to the public address, because webhook and form links are built from it.
