![cover](/assets/cover.jpg)
# Git Web Manager

Git Web Manager (GWM) is a self-hosted Laravel + Livewire application for deploying and monitoring Git-backed websites from a single dashboard. It handles deploys, rollbacks, health checks, preview builds by commit, dependency actions, and a security overview for Dependabot alerts.

[![demo](/assets/snapshot.jpg)](https://wallabypanel.com/link/gitwebmanager)

Git Web Manager is not affiliated with, endorsed by, or sponsored by Git or GitHub. Show your support if you found this useful and want to support our efforts to provide meaningful content!

[![please donate](https://img.shields.io/liberapay/receives/wallaby.svg?logo=liberapay)](https://liberapay.com/wallaby/donate)

## Documentation Site (GitHub Pages)
To review the documentation for this project, click here: [documentation](https://docs.gitwebmanager.com).

[![documentation](/assets/docs.png)](https://docs.gitwebmanager.com)

## Why Use It
- Replace manual `git pull` + build + rollback steps with one UI.
- Get per-project health checks and recent activity logs.
- Spin up preview builds for any commit.
- Keep dependencies and security alerts visible.
- Deploy to FTP servers without giving them direct server access.

## Feature Overview

### Core
- **Project management:** Create and manage Git-backed projects with per-project settings, paths, branches, and deployment behavior.
- **Deploy workflows:** Run deploy, force deploy, and rollback actions with logs and status history.
- **Task queue:** Queue and process background work in order (including deploy-related tasks and Enterprise audit jobs), with controls to reorder, cancel, and process now.
- **Auto deploy + webhooks:** Trigger deployments from scheduled checks or GitHub webhook events.
- **Health monitoring:** Track project health endpoints with live state and last-checked visibility.
- **Preview builds:** Generate preview builds for specific commits to validate changes safely.
- **Dependency operations:** Run composer/npm actions with per-run logs and issue visibility.
- **Per-project `.env` editor:** Edit each project's environment file directly from the UI without server access.
- **FTP deploy targets:** Configure FTP accounts and deploy projects into managed FTP workspaces — useful for hosts that only expose FTP.
- **Workflow automations:** Configure rule-based notifications and webhooks for deploy and audit events. Activity emails are consolidated into hourly digest reports to prevent repeated failure notifications.

### Security & Auditing
- **Security insights:** Review Dependabot and audit findings in one place, including remediation workflows.
- **Enterprise audit automation:** Enable scheduled project dependency audits plus managed container runtime audits. Eligible projects are audited hourly and remediation work is queued automatically when auditing is enabled.
- **Audit log & alerts:** View a full activity log of system and deploy events, and configure rule-based email or webhook notifications for success/failure outcomes.

### Infrastructure
- **Container control center:** Manage Docker nodes, runtime health, containers, and managed PostgreSQL/MySQL database containers from one workspace. Create containers with the form or paste a `docker run` command to deploy immediately or prefill the form for review.
- **Tiered container licensing:** Community edition includes Docker with up to 3 nodes; Enterprise unlocks unlimited nodes and premium automation.
- **Scheduler health:** Monitor heartbeat status, run scheduler actions manually, and manage cron setup from the UI.
- **Runtime diagnostics:** Check the live status of PHP, Composer, Node.js/npm, Python, and pip directly from the System Control Center, with inline install actions for missing tools. Also manages the bundled Node.js LTS runtime — GWM can download and install Node.js into its own storage directory with no system-level install required.

### System & Administration
- **App self-update:** Update the manager itself with safe defaults and force-update recovery options. Updates run under maintenance mode to prevent broken-state errors mid-deploy.
- **Recovery tools:** Create, restore, and delete `.env` backups from the UI, and trigger a full app rebuild from the recovery page.
- **Environment config editor:** Edit the application `.env` file and manage environment variables directly from the System Control Center.
- **Email settings:** Configure the mail driver, SMTP credentials, sender address, and send test emails from the UI. Application emails use the GWM layout, include a plain-text fallback, and place lengthy error output behind a "View error log" disclosure.
- **GitHub OAuth:** Enable GitHub-based login as an authentication option, configured from App & Security settings.
- **Cloudflare Turnstile:** Add bot protection to the login form without a traditional CAPTCHA, configured from App & Security settings.
- **User management:** Manage users, enforce first-login password changes, and configure role-based access.
- **Multi-language:** 14 languages supported out of the box with per-user or session-level switching.

### Enterprise
- **White label branding:** Customize the application name, logo, favicon, and sub-heading, and optionally hide the edition label — all from the System Control Center.
- **Enterprise support portal:** Submit and manage support tickets directly from within the app.

## Quick Start
1. Copy `.env.example` to `.env` and configure required values.
2. Install PHP dependencies.
3. Run migrations and start the app.

```bash
composer install
php artisan migrate
```

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
- Git CLI available to the web user.
- Queue worker for webhook deploys.
- Scheduler for auto-deploy and security sync.
- Node.js is optional. It is only needed when deploying projects that run npm commands. GWM can install a bundled Node.js LTS runtime automatically via the Runtime Diagnostics page in System Control Center — no system-level install required.

## Larust Projects

Select **Larust** as the project type (Enterprise, like Rust). New projects enable `cargo build --release` and `cargo test`, with Composer, npm, and automatic dependency updates disabled. Cargo and the app's native build dependencies must be installed on the host executing the build (the SSH host for SSH deployments).

Set Local Path to your Larust application's directory containing `Cargo.toml`. For a framework workspace such as `E:\vsprojects\Larust`, use workspace commands (`cargo build --release --workspace` and `cargo test --workspace`) to build and test the framework; select an app package explicitly when deploying a web server. Local path dependencies must also exist on the deployment host.

Configure your app's `.env` and Site URL. Health Check URL is blank by default because generated apps do not necessarily register `/up`; set it only when your app provides that route. Use an application deployment script for migrations (for example, `cargo run --release -- migrate` in a single-app package) and service restarts. GWM runs the configured build/test commands but does not automatically start a Larust server or run its migrations. Serve the release binary through your process supervisor and reverse proxy; FTP upload alone cannot start a Rust server.

## Permissions (Important)
Git operations are performed by the web server user. For reliable updates, the PHP-FPM user should match the filesystem owner of the app and project directories. If they differ, git may fail to write to `.git/objects` or `.git/index`.

## Configuration Highlights
Set these in `.env` as needed:
- `GITHUB_TOKEN` for private repos + Dependabot actions.
- `GITHUB_WEBHOOK_SECRET` for webhook verification.
- `GWM_GIT_BINARY`, `GWM_COMPOSER_BINARY`, `GWM_NPM_BINARY` for custom CLI paths.
- `GWM_PHP_BINARY` / `GWM_PHP_PATH` for PHP CLI selection.
- `GWM_DOCKER_BINARY` / `GWM_DOCKER_COMPOSE_BINARY` / `GWM_KUBECTL_BINARY` for container CLI overrides.
- Docker/Octane builds install Composer dependencies inside the image. Private package access is passed as `COMPOSER_AUTH` automatically from the saved license when available; otherwise set `COMPOSER_AUTH` in the environment used to start the build.
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

## Scheduler & Queue
Start a worker for webhook deployments:
```bash
php artisan queue:work
```

Preferred for busy hosts: run the scheduler as a supervised long-running worker so it does not depend on cron firing exactly on time:
```bash
php artisan scheduler:work
```

The worker reuses GitManager's scheduler wrapper, so it records the System Scheduler heartbeat, releases stale scheduler locks, logs scheduler issues, and checks due Laravel scheduled tasks every `GWM_SCHEDULER_WORKER_SLEEP_SECONDS` seconds.

Cron is still supported for existing installs:
```bash
* * * * * cd /path/to/app && /path/to/php artisan scheduler:run >/dev/null 2>&1
```

The `scheduler:run` wrapper records the System Scheduler heartbeat first, then runs Laravel's scheduled tasks. Existing installations that already use this cron line do not need to change it.

Docker Compose uses `scheduler:work` for the scheduler service. If Docker and Docker Compose are available to the app, System → Plugins can also start, stop, and restart the app-managed Octane instance. Octane listens on `OCTANE_PORT` or port `8000` by default and uses FrankenPHP unless `OCTANE_SERVER` is changed.

Octane is optional. Starting it creates and manages the local Docker Compose Octane profile from the System Control Center; it does not automatically move existing browser or reverse-proxy traffic to port `8000`. Point the web server or proxy at the displayed Octane endpoint before treating it as the application's active HTTP runtime. The app continues to use the normal PHP web-server path until that routing change is made.

### Rust Operations Executor
The optional Rust Operations Executor is built from the bundled `executor/` source through Docker Compose. Use **System → Plugins → Runtime Services → Install Executor** to build and start it without installing Rust or Cargo on the host. It is available only inside the Compose network at `http://rust-executor:8787/health`; no executor port is published publicly.

This first executor release provides a managed, health-checked runtime foundation. Laravel remains the source of truth for queue jobs and existing PHP workers continue processing deployments, updates, and audits. Executor job claiming will be added behind an explicit opt-in after its authenticated, idempotent job protocol is available.

The executor image uses the bundled `executor/Cargo.lock` for reproducible builds. Do not commit `executor/target/`; it contains local Rust build artifacts and is excluded by `.gitignore`.

Docker is the managed executor backend today. A native-host backend is planned for installations that need direct access to arbitrary host project paths; PHP workers remain the fallback for shared hosting and unsupported targets.

The **Run Scheduler Now** control performs a due-task run and starts the managed scheduler worker when it is not already available. For production, keep `scheduler:work` supervised by Docker, systemd, Supervisor, or an equivalent process manager. Cron remains a supported fallback for shared hosting.

Scheduled commands include:
- `app:self-audit` (every 10 minutes)
- `projects:auto-deploy` (every 5 minutes)
- `projects:health-check` (every 5 minutes)
- `deployments:process-queue` (every minute)
- `security:sync` (hourly)
- `dependabot:auto-merge` (hourly)
- `projects:audit` (hourly; valid Enterprise license and dependency auditing enabled)
- `notifications:send-digest` (hourly)
- `gitmanager:self-update` (daily at 02:30 if enabled)

### Email Digests
Deployment failures, queue failures, audit findings, and resolved audit events are grouped by recipient and delivered as a single activity report after the configured cooldown (60 minutes by default). Repeated records for the same deployment or audit issue are consolidated before sending, with the latest available error output retained. Site health failures remain immediate alerts because they can indicate an unavailable website.

For Community installations, application emails include a small Enterprise note. Enterprise installations do not receive that promotion.

## Webhooks
Set GitHub to POST to:
```
/webhooks/github
```

Use the same `GITHUB_WEBHOOK_SECRET` in GitHub and `.env`.

## Enterprise Checkout Flow
- Checkout entry point: `POST /checkout/enterprise`.
- Success redirect: `/checkout/enterprise/success`.
- Cancel redirect: `/checkout/enterprise/cancel`.
- Trusted testing installs can use `/checkout/enterprise/testing` to validate UX without charging a card.
- The app requests a checkout URL from the website API; Stripe secrets and Stripe webhooks stay on the website.
- Successful checkout returns to the app, which re-verifies the license and activates enterprise access.

## App Updates
The app can update itself from its repo. By default it preserves local changes when detected. If an update fails, you can run a **Force Update** to hard-reset to the remote branch while preserving `.env`, `storage/`, `.htaccess`, and `GWM_SELF_UPDATE_EXCLUDE_PATHS`.
If the app exposes the custom `app:clear-cache` command, it runs automatically after updates.
Force Update does not clear app data (logs, storage files, and other protected paths are retained).

CLI:
```bash
php artisan gitmanager:self-update
php artisan gitmanager:self-update --force
```

## FTP Deploy Targets
Projects can be configured to deploy into a managed FTP workspace rather than a local path. Add FTP accounts under **FTP Accounts** in the main navigation, then assign one to a project. GWM syncs files from the Git workspace into the FTP target during each deploy.

## Recovery
The `/recovery` page provides:
- Create, restore, and delete `.env` backups without server access.
- Trigger a full application rebuild (clears caches, re-runs setup) from the UI.

## User Management & First Login
- Registration is open only when there are no users (first admin setup).
- After the first account exists, create users from the **Users** page in the main navigation.
- Users created by admins can be forced to change their password on first login.
- Use “Send reset link” for email-driven password recovery.

## Contributing
Issues and pull requests are welcome. Please include clear reproduction steps and environment details for bugs.

## License
zlib License. See LICENSE for details.
