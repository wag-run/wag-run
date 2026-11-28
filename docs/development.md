# Local development

This page covers running wag.run from a checkout on your own machine, for working on the code. To run it for a team, see [Self-hosting](self-hosting.md) instead.

## Prerequisites

- **PHP 8.4 or newer**, with the `pdo_sqlite` extension.
- **[Composer](https://getcomposer.org)**
- **Node.js** and npm. The Docker image builds assets with Node 26.
- **Git**

On macOS, [Herd](https://herd.laravel.com) installs PHP, Composer, and Node for you.

## 1. Clone the repository

```bash
git clone https://github.com/wag-run/wag-run.git
cd wag-run
```

## 2. Install and set up the app

```bash
composer run setup
```

This installs the PHP and npm dependencies, creates `.env` from `.env.example`, generates an `APP_KEY`, runs the migrations, and builds the front-end assets. Local development uses SQLite (`database/database.sqlite`) and the database queue and cache, so there's nothing else to start.

## 3. Install the AI agent skills and guidelines

```bash
php artisan boost:update
```

**Run this as part of every new setup.** wag.run uses [Laravel Boost](https://github.com/laravel/boost) to give AI coding agents its guidelines and skills. The setup is committed in `boost.json`, `AGENTS.md`, `.mcp.json` and `.ai/rules`, but the skills themselves are not: `boost:update` generates them into `.claude/skills` from the packages installed in `vendor/`, so they always match the versions you have. `.claude` is ignored by Git.

Run it again after `composer update`, or whenever you pull changes to `composer.lock` or `boost.json`, so the skills and `AGENTS.md` stay current.

The `.ai/skills` folder is for overrides only. Boost treats anything in it as a project-specific skill and copies it over the generated one with the same name, so don't move or copy Boost's generated skills into it. Add a folder there only when you want to replace or add a skill on purpose.

## 4. Start the app

```bash
composer run dev
```

This runs `php artisan dev`, which starts the local web server and the other processes the app needs while you work. Open the URL it prints and register. The first account becomes the organization admin.

### Working on the hosted version

wag.run also runs as a hosted service. The parts only it needs are behind the `SELF_HOSTED` setting, which is `true` by default. To work on them, add `SELF_HOSTED=false` to your `.env`.

## Tests and checks

```bash
php artisan test --compact   # the test suite
vendor/bin/pint              # code style
composer run types:check     # static analysis (Larastan)
```

`composer test` runs all three. CI runs the same three on every pull request with `composer ci:check`, which runs the test suite in parallel.
