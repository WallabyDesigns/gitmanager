# Git Project Manager

Git Project Manager is a Laravel Livewire application for managing deployments of your published GitHub projects. It keeps track of local paths, watches for upstream Git updates, runs dependency installs/builds, and provides rollback + health monitoring from a single interface.

## Features
- Authenticated dashboard for projects
- Manual deploys, rollbacks, health checks, and dependency updates
- Auto-deploy on a schedule when new commits are detected
- GitHub webhook endpoint for push-triggered deploys
- Security page with Dependabot alert tracking
- Dark theme interface
 - Optional pre-deploy test command

## Getting started
1. Configure your environment in `.env`.
1. Run migrations.
1. Start the app and open the `/projects` dashboard.

## Notes
- Deployment commands run on the same host where the app is installed, so the application needs access to each project path.
- Each `local_path` should be an existing Git repository with an `origin` remote and the configured default branch.
- Git, Composer, and Node must be available on the server for deploys and builds.
- Auto deploy runs with `projects:auto-deploy` and is scheduled every five minutes.
- For GitHub webhooks, set `GITHUB_WEBHOOK_SECRET` and point GitHub to `/webhooks/github`.
 - Make sure `repo_url` matches the GitHub repo URL used in the webhook payload (HTML, SSH, or clone URL).
- Webhook deploys are queued, so run a queue worker (`php artisan queue:work`).
 - For Dependabot sync and auto-merge, set `GITHUB_TOKEN`.
