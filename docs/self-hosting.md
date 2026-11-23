# Self-hosting

wag.run ships as a Docker image. One container runs the web server, the queue workers that run workflow steps, and the scheduler. A second container runs PostgreSQL. Both are locked down by default: they run as unprivileged users, with read-only filesystems and no Linux capabilities.

## Requirements

- Docker with the Compose plugin. Rootless Docker and Podman work too.
- About 1 GB of memory for each container. You can change the limits in the settings.
- A domain name and a reverse proxy for HTTPS if people will reach the install over the internet.

## Install

1. Get the code and create your settings file:

   ```bash
   git clone https://github.com/wag-run/wag-run.git wag.run
   ```

   ```bash
   cd wag.run && cp .env.docker.example .env
   ```

2. Generate a database password:

   ```bash
   echo "DB_PASSWORD=$(openssl rand -hex 24)"
   ```

   Copy the line it prints into `.env`, replacing the empty `DB_PASSWORD=`. This is the only setting you must fill in.

3. Build and start the containers:

   ```bash
   docker compose up -d --build
   ```

   On first start, the app generates an encryption key (`APP_KEY`), saves it in its storage volume and creates the database tables.

4. Open <http://localhost:8080> and **register**. The first account becomes the organization admin, so do this before anyone else can reach the install.

5. As the admin, set up [email](administration.md#email) so invitations and approval requests reach people, then create a [workspace](administration.md#workspaces) and [invite your team](administration.md#users).

## Settings

All settings live in `.env`, next to `docker-compose.yml`. After changing one, apply it with:

```bash
docker compose up -d
```

| Setting | Default | What it does |
|---|---|---|
| `DB_PASSWORD` | required | Password for the bundled PostgreSQL database |
| `APP_KEY` | generated | Encrypts sessions and stored secrets. Left empty, one is generated on first run and kept in the storage volume as `app.key`. See [The encryption key](#the-encryption-key). |
| `APP_URL` | `http://localhost:8080` | The address people use to reach wag.run. Webhook and form trigger links are built from it, so set it to your real address. |
| `APP_BIND` | `127.0.0.1` | The host address the port is published on. Keep it when a reverse proxy on the same machine forwards to the app. Use `0.0.0.0` to expose the port directly. |
| `APP_PORT` | `8080` | The host port |
| `TRUSTED_PROXIES` | empty | Your reverse proxy's IP addresses or CIDR ranges, comma-separated, or `*` if the app can only be reached through the proxy |
| `SESSION_SECURE_COOKIE` | `false` | Set to `true` once the site is served over HTTPS |
| `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS` | `log` | SMTP settings for outgoing email. You can set email up in the app instead, under **Organization → Email**. |
| `APP_NAME` | `wag.run` | The name shown in the app and in emails |
| `QUEUE_WORKERS` | `2` | How many workers run workflow steps at once |
| `LOG_LEVEL` | `info` | How much the app logs |
| `APP_MEMORY_LIMIT`, `DB_MEMORY_LIMIT` | `1g` | Memory limit for each container |

Until email is set up, outgoing mail is written to the log instead of sent.

## HTTPS

The app container serves plain HTTP. Put a reverse proxy that handles TLS, such as Caddy, Traefik or nginx, in front of it, forwarding to `127.0.0.1:8080`. Then set these in `.env`:

```dotenv
APP_URL=https://wag.example.com
TRUSTED_PROXIES=127.0.0.1
SESSION_SECURE_COOKIE=true
```

Set `TRUSTED_PROXIES` to the address your proxy connects from. Without it, the app doesn't trust the proxy's forwarded headers and builds `http://` links.

Requests to the app, such as webhook calls, can be up to 25 MB.

## The encryption key

`APP_KEY` encrypts sessions and everything stored as secret: workspace secrets, authentication settings and the organization's SMTP password.

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
| `wag_database` | The PostgreSQL database: workflows, runs, users and settings |
| `wag_storage` | The app's own files, including the generated `APP_KEY` |

Back up both. To dump the database:

```bash
docker compose exec db pg_dump -U wag wag > backup.sql
```

If you set `APP_KEY` yourself, keep a copy of it with your backups.

## Upgrading

Pull the new code and rebuild:

```bash
git pull && docker compose up -d --build
```

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

For a small or trial install, the image can store its data in SQLite inside the storage volume. It does this when no `DB_*` settings are given. Build the image, put only `APP_URL` in `.env`, and start it on its own:

```bash
docker build -t wag.run .
```

```bash
docker run -d --read-only --cap-drop ALL --security-opt no-new-privileges --tmpfs /tmp --env-file .env -v wag_storage:/var/www/html/storage -p 127.0.0.1:8080:8080 wag.run:latest
```

Back up the `wag_storage` volume, which then holds the database too.

## How the containers are locked down

- Every process runs as an unprivileged user. Nothing starts as root, and the app refuses to start if you override it to run as root.
- All Linux capabilities are dropped, and processes can't gain new privileges.
- Root filesystems are read-only. The app writes only to its storage volume and to a `/tmp` that can't run programs. The application code is owned by root and the app can't change it.
- The database has no published port. It sits on an internal network that only the app can reach and that has no route to the internet.
- The image contains only what runs the app: no build tools, development dependencies, tests or `.env` files.
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
