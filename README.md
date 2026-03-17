# Git Web Manager

Git Web Manager is a self-hosted Laravel Livewire app for deploying and monitoring GitHub projects from a single dashboard. It handles deploys, rollbacks, health checks, preview builds, and Dependabot automation.

Git Web Manager is not affiliated with, endorsed by, or sponsored by Git or its maintainers.

## Support:
This is a completely free service that I work hard to maintain. Show your support if you found this useful!

[![wallaby](/coffee.png)](https://www.buymeacoffee.com/wallaby)


## Read Documentation
Please read our [documentation](https://wallabydesigns.github.io/gitmanager/) to help you get up and running.

## Features
- Authenticated dashboard with project list and detail views.
- Manual deploys, force deploys, and rollbacks.
- Auto-deploy via scheduler or GitHub webhooks.
- Health checks with live status.
- Preview builds for any commit.
- Dependency actions (composer/npm) with logs.
- Security tab with Dependabot alerts and auto-merge.
- Self-update flow for the app itself.
- User management tab for creating accounts and resetting passwords.
- App updates automatically preserve local changes when detected.
- Project deploys automatically stash and restore local tracked changes (force deploy remains destructive).

## Quick Start
1. Copy `.env.example` to `.env` and configure required variables.
2. Install dependencies and build assets.
3. Run migrations and start the app.

```
composer install
php artisan migrate
npm install
npm run build
```

## Requirements
- PHP 8.2+ with required extensions (mbstring, curl, etc).
- Composer 2.
- Node.js 18+ (or 20/22) for Vite builds.
- Git CLI available to the web user.
- Queue worker for webhook deploys.
- Scheduler for auto-deploy and security sync.

## Configuration Highlights
Set these in `.env` as needed:
- `GITHUB_TOKEN` for private repos + Dependabot actions.
- `GITHUB_WEBHOOK_SECRET` for webhook verification.
- `GWM_GIT_BINARY`, `GWM_COMPOSER_BINARY`, `GWM_NPM_BINARY` for custom CLI paths.
- `GWM_PHP_BINARY` / `GWM_PHP_PATH` for PHP CLI selection.
- `GWM_PROCESS_PATH` to prepend PATH (Node, PHP, etc).
- `GWM_SELF_UPDATE_ENABLED` to enable nightly self updates.
- `GWM_SELF_UPDATE_EXCLUDE_PATHS` to skip paths (like `docs`) during app updates.
- `GWM_PREVIEW_PATH` and `GWM_PREVIEW_BASE_URL` for preview builds.
Legacy `GPM_*` keys are still supported for backward compatibility.

## Scheduler & Queue
Start a worker for webhook deployments:
```
php artisan queue:work
```

Ensure the scheduler runs (crontab entry):
```
* * * * * php /path/to/artisan schedule:run >> /dev/null 2>&1
```

Scheduled commands include:
- `projects:auto-deploy` (every 5 minutes)
- `security:sync` (hourly)
- `dependabot:auto-merge` (hourly)
- `gitmanager:self-update` (daily at 02:30 if enabled)

## GitHub Webhooks
Set GitHub to POST to:
```
/webhooks/github
```

Use the same `GITHUB_WEBHOOK_SECRET` in GitHub and `.env`.

## Documentation Site (GitHub Pages)
This repo ships a static docs site in `docs/`.

To publish on GitHub Pages:
1. Go to repo settings → Pages.
2. Select **Deploy from a branch**.
3. Choose branch `main` and folder `/docs`.

## License
Add your preferred open-source license before publishing.

## User Management & First Login
- Registration is open only when there are no users (first admin setup).
- After the first account exists, create users from the **Users** page in the main navigation.
- Users created by admins can be forced to change their password on first login.
- Use “Send reset link” for email-driven password recovery.

