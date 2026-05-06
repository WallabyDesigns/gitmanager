![cover](/assets/cover.jpg)
# Git Web Manager

Git Web Manager (GWM) is a self-hosted Laravel + Livewire application for deploying and monitoring Git-backed websites from a single dashboard. It handles deploys, rollbacks, health checks, preview builds by commit, dependency actions, and a security overview for Dependabot alerts.

[![demo](/assets/snapshot.jpg)](https://wallabypanel.com/link/gitwebmanager)

Git Web Manager is not affiliated with, endorsed by, or sponsored by Git or GitHub. This is a completely free service that I work hard to maintain. Show your support if you found this useful and want to support our efforts to provide meaningful content!

[![please donate](https://img.shields.io/liberapay/receives/wallaby.svg?logo=liberapay)](https://liberapay.com/wallaby/donate)

## Documentation Site (GitHub Pages)
The docs site is maintained in a separate repository: `gitmanager-docs`.
Local workspace path:
- `E:\vsprojects\gitmanager-docs`

You can read them directly here: [documentation](https://docs.gitwebmanager.com).

[![documentation](/assets/docs.png)](https://docs.gitwebmanager.com)

## Why Use It
- Replace manual `git pull` + build + rollback steps with one UI.
- Get per-project health checks and recent activity logs.
- Spin up preview builds for any commit.
- Keep dependencies and security alerts visible.

## Feature Overview
- **Project management:** Create and manage Git-backed projects with per-project settings, paths, branches, and deployment behavior.
- **Path conflict awareness:** `local_path` values can be reused across projects (including FTP projects using common paths like `/public_html`), and the UI now shows a warning when a path is shared.
- **Deploy workflows:** Run deploy, force deploy, and rollback actions with logs and status history.
- **Task queue:** Queue and process background work in order (including deploy-related tasks and Enterprise audit jobs), with controls to reorder, cancel, and process now.
- **Container control center:** Manage Docker nodes, runtime health, containers, and managed PostgreSQL/MySQL database containers from one workspace.
- **Tiered container licensing:** Community edition includes Docker with up to 3 nodes; Enterprise unlocks unlimited nodes and premium automation.
- **Scheduler health:** Monitor heartbeat status, run scheduler actions manually, and manage cron setup from the UI.
- **Auto deploy + webhooks:** Trigger updates from scheduled checks or GitHub webhook events.
- **Health monitoring:** Track project health endpoints with live state and last-checked visibility.
- **Preview builds:** Generate preview builds for specific commits to validate changes safely.
- **Dependency operations:** Run composer/npm actions with per-run logs and issue visibility.
- **Enterprise audit automation:** Enable scheduled project dependency audits plus managed container runtime audits.
- **Security insights:** Review Dependabot and audit findings in one place, including remediation workflows.
- **Workflow automations:** Configure rule-based notifications and webhooks for success/failure events.
- **App self-update:** Update the manager itself with safe defaults and force-update recovery options.
- **Admin controls:** Manage users, enforce first-login password changes, and configure system/email settings.

## Quick Start
1. Copy `.env.example` to `.env` and configure required values.
2. Install dependencies and build assets.
3. Run migrations and start the app.

```bash
composer install
php artisan migrate
npm install
npm run build
```

## Enterprise Package Source
`wallabydesigns/gitmanager-enterprise` is intended to be installed by default because it contributes shared/free-version functionality as well as enterprise-only capabilities.

- Premium access should be decided by the licensing website/API at runtime, not by prompting end users for repository credentials in the terminal.
- The app no longer writes Composer auth to `auth.json` or tries to bootstrap terminal credentials itself.
- If Composer is still prompting for `gitwebmanager.com` credentials, that indicates the remote package endpoint still needs to be adjusted to allow the default install flow without terminal authentication.

## Docker Installation
Docker is required to run the container setup.

Windows/macOS:
Install Docker Desktop, then verify:

```bash
docker version
docker compose version
```

Linux:
Install Docker Engine and the Docker Compose plugin from the official Docker docs, then verify:

```bash
docker version
docker compose version
```

Ensure the Docker engine is running before starting containers.

## Docker Quick Start
1. Copy `.env.example` to `.env` and configure required values.
2. Ensure Docker Desktop is running.
3. Build and start containers:

```bash
docker compose --env-file .env.docker up -d --build
```

4. Open `http://localhost:8080`

Notes:
- `.env.docker` is for Docker Compose interpolation only.
- The app reads settings from `.env` (mounted read-only into the containers).
- Change the exposed port by editing `APP_PORT` in `.env.docker`.
- Stop containers with `docker compose down`.

If any value in `.env` contains a `$`, wrap it in single quotes to avoid Compose interpolation warnings when running Docker on some systems.

## Requirements
- PHP 8.2+ with required extensions (mbstring, curl, etc).
- Composer 2.
- Node.js 18+ (or 20/22) for Vite builds.
- Git CLI available to the web user.
- Queue worker for webhook deploys.
- Scheduler for auto-deploy and security sync.

## Permissions (Important)
Git operations are performed by the web server user. For reliable updates, the PHP-FPM user should match the filesystem owner of the app and project directories. If they differ, git may fail to write to `.git/objects` or `.git/index`.

## Configuration Highlights
Set these in `.env` as needed:
- `GITHUB_TOKEN` for private repos + Dependabot actions.
- `GITHUB_WEBHOOK_SECRET` for webhook verification.
- `GWM_GIT_BINARY`, `GWM_COMPOSER_BINARY`, `GWM_NPM_BINARY` for custom CLI paths.
- `GWM_PHP_BINARY` / `GWM_PHP_PATH` for PHP CLI selection.
- `GWM_DOCKER_BINARY` / `GWM_KUBECTL_BINARY` for container CLI overrides.
- `GWM_PROCESS_PATH` to prepend PATH (Node, PHP, etc).
- `GWM_SELF_UPDATE_ENABLED` to enable self updates.
- `GWM_SELF_UPDATE_EXCLUDE_PATHS` to skip paths (default: `docs`).
- `GWM_PREVIEW_PATH` and `GWM_PREVIEW_BASE_URL` for preview builds.
- `GWM_DEPLOY_QUEUE_ENABLED` to enable queued tasks.
- `GWM_LICENSE_ALLOW_INSECURE_LOCAL_TLS` for local/testing only when your dev machine cannot validate the license server certificate chain.
- Enterprise licensing runtime config (verification URL, timeout, cache window, IP policy, signature secret) is resolved from the private enterprise package.
- Commercial package values (edition mapping, product IDs, tier limits, price display) are managed in the private enterprise package.
- Enterprise access is resolved from signed license responses (including installation UUID + optional IP checks), not local public-env edition toggles.

Legacy `GPM_*` keys are still supported for backward compatibility.

## Localization
Git Web Manager supports multiple languages out of the box. Users can switch language from the UI, and the preference is saved per-user or in the session for guests.

### Supported Languages
| Language | Code |
|----------|------|
| English | `en` |
| Spanish (Español) | `es` |
| French (Français) | `fr` |
| German (Deutsch) | `de` |
| Italian (Italiano) | `it` |
| Portuguese - Brazil (Português) | `pt_BR` |
| Dutch (Nederlands) | `nl` |
| Polish (Polski) | `pl` |
| Swedish (Svenska) | `sv` |
| Japanese (日本語) | `ja` |
| Korean (한국어) | `ko` |
| Chinese Simplified (简体中文) | `zh_CN` |
| Hindi (हिन्दी) | `hi` |
| Arabic (العربية) | `ar` |

### Configuration
Set the default locale in `.env`:
```bash
APP_LOCALE=en
APP_FALLBACK_LOCALE=en
```

### Contributing Translations
Translation files are stored in `lang/*.json` as Laravel JSON language files. To contribute improvements:
1. Edit the relevant `lang/<locale>.json` file
2. Keep the key in English; translate only the value
3. Submit a pull request with your changes

The translation script (for maintainers) is located at `.local/translate-lang.php` and can be used to machine-translate new strings added to `en.json`.

## Scheduler & Queue
Start a worker for webhook deployments:
```bash
php artisan queue:work
```

Ensure the scheduler runs (crontab entry):
```bash
* * * * * cd /path/to/app && /path/to/php artisan scheduler:run >/dev/null 2>&1
```

The `scheduler:run` wrapper records the System Scheduler heartbeat first, then runs Laravel's scheduled tasks. Existing installations that already use this cron line do not need to change it.

Scheduled commands include:
- `app:self-audit` (every 10 minutes)
- `projects:auto-deploy` (every 5 minutes)
- `projects:health-check` (every 5 minutes)
- `deployments:process-queue` (every minute)
- `security:sync` (hourly)
- `dependabot:auto-merge` (hourly)
- `gitmanager:self-update` (daily at 02:30 if enabled)

## Webhooks
Set GitHub to POST to:
```
/webhooks/github
```

Use the same `GITHUB_WEBHOOK_SECRET` in GitHub and `.env`.

Enterprise Stripe events should POST to:
```
/webhooks/stripe
```

Stripe webhook secret is read from enterprise runtime config (encrypted settings) with enterprise package env fallback support.

## Enterprise Checkout Flow
- Checkout entry point: `POST /checkout/enterprise`.
- Success redirect: `/checkout/enterprise/success`.
- Cancel redirect: `/checkout/enterprise/cancel`.
- Trusted testing installs can use `/checkout/enterprise/testing` to validate UX without charging a card.
- Successful checkout and Stripe payment webhooks trigger license verification and enterprise activation logic.

## App Updates
The app can update itself from its repo. By default it preserves local changes when detected. If an update fails, you can run a **Force Update** to hard-reset to the remote branch while preserving `.env`, `storage/`, `.htaccess`, and `GWM_SELF_UPDATE_EXCLUDE_PATHS`.
If the app exposes the custom `app:clear-cache` command, it runs automatically after updates.
Force Update does not clear app data (logs, storage files, and other protected paths are retained).

CLI:
```bash
php artisan gitmanager:self-update
php artisan gitmanager:self-update --force
```

To publish docs on GitHub Pages:
1. Open the `gitmanager-docs` repository settings → Pages.
2. Select **Deploy from a branch**.
3. Choose the docs repo `main` branch root.

## User Management & First Login
- Registration is open only when there are no users (first admin setup).
- After the first account exists, create users from the **Users** page in the main navigation.
- Users created by admins can be forced to change their password on first login.
- Use “Send reset link” for email-driven password recovery.

## Contributing
Issues and pull requests are welcome. Please include clear reproduction steps and environment details for bugs.

## License
zlib License. See LICENSE for details.
