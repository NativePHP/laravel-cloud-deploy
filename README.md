# Laravel Cloud Deploy

Deploy Laravel applications to [Laravel Cloud](https://cloud.laravel.com) from the command line.

## Sponsor

This project is sponsored by [Bifrost](https://bifrost.nativephp.com) - the fastest way to ship native apps with AI.

## Installation

```bash
composer require nativephp/laravel-cloud-deploy
```

## Configuration

Publish the configuration file:

```bash
php artisan vendor:publish --tag=cloud-config
```

This will create a `config/cloud.php` file where you can configure your deployment settings.

### Environment Variables

Add your Laravel Cloud API token to your `.env` file:

```env
LARAVEL_CLOUD_TOKEN=your-api-token
LARAVEL_CLOUD_REPOSITORY=owner/repo
LARAVEL_CLOUD_REGION=us-east-2
LARAVEL_CLOUD_SOURCE_CONTROL=github
```

Generate an API token in your Laravel Cloud organization settings, under "API tokens". `LARAVEL_CLOUD_SOURCE_CONTROL`
defaults to `github`; the other options are `gitlab`, `gitlab_self_hosted` and `bitbucket`. The provider has to be
connected to your Cloud organization before the application can be created.

### Supported Regions

- `us-east-2` (Ohio)
- `us-east-1` (N. Virginia)
- `ca-central-1` (Canada)
- `eu-west-1` (Ireland)
- `eu-west-2` (London)
- `eu-central-1` (Frankfurt)
- `me-central-1` (UAE)
- `ap-southeast-1` (Singapore)
- `ap-southeast-2` (Sydney)
- `ap-northeast-1` (Tokyo)

## Usage

### Deploy All Environments

```bash
php artisan cloud:deploy
```

### Deploy Specific Environment

```bash
php artisan cloud:deploy production
```

### Options

| Option | Description |
|--------|-------------|
| `--skip-deploy` | Configure infrastructure without initiating a deployment |
| `--force` | Skip confirmation prompts |
| `--dry-run` | Show what would be done without making changes |

### Examples

Preview changes without deploying:

```bash
php artisan cloud:deploy --dry-run
```

Configure infrastructure only (useful for initial setup):

```bash
php artisan cloud:deploy --skip-deploy --force
```

Deploy production with no prompts:

```bash
php artisan cloud:deploy production --force
```

## Migrating from Forge

`cloud:migrate-from-forge` moves a Laravel site from [Laravel Forge](https://forge.laravel.com) to Laravel Cloud. It is a
guided wizard: every step says what it is about to do and asks before it creates or changes anything.

```bash
php artisan cloud:migrate-from-forge
```

Run it from the project you're migrating, since it writes `config/cloud.php` there and looks through your code for
things that won't work on Cloud.

### Before you start

- A Forge API token, created at [forge.laravel.com/profile/api](https://forge.laravel.com/profile/api) with these
  scopes: `organization:view`, `server:view`, `site:meta`, `site:manage-redirects`, `site:manage-security`,
  `server:create-keys`, `server:delete-keys` and `site:manage-commands`.
- A Laravel Cloud API token from the organization you're moving to (organization settings > API tokens).
- GitHub connected to your Cloud account (Account settings > Source control), with the
  [Laravel Cloud GitHub app](https://github.com/apps/laravel-cloud-app/installations/select_target) given access to
  the repository. The Cloud API can't check this, so the wizard asks and it's confirmed when the app is created.
- The site's code on GitHub. Other providers aren't supported yet.
- `ssh` and `ssh-keygen` on your machine. On the Forge server, `mysqldump` and `mysql` (or `pg_dump` and `psql`), which
  Forge installs. For files, `rclone` or the AWS CLI on the server is fastest. Without them, files are rsynced to your
  machine and uploaded from there, which needs `rsync` and `league/flysystem-aws-s3-v3`.

If `FORGE_API_TOKEN` or `LARAVEL_CLOUD_TOKEN` aren't in your `.env`, the wizard asks for them and offers to save them.

### What it does

1. **Setup**: checks both tokens against the APIs, has you pick the Forge organization, confirms the Cloud
   organization and checks GitHub is connected.
2. **Site**: pick the Forge server and site.
3. **Inspect**: reads the site's settings, `.env`, deploy script, background processes, scheduled jobs, database,
   domains, Nginx config, redirect and security rules, and integrations (Horizon, Octane, Reverb, scheduler).
4. **Report**: a table of what will migrate, what needs doing by hand and anything that blocks the move.
5. **Config**: writes `config/cloud.php` from what it found, with a diff if you already have one.
6. **Provision**: runs `cloud:deploy --skip-deploy` to create the app, environment, instance, workers, database, cache
   and buckets.
7. **Environment**: copies the Forge `.env` to Cloud, leaving out variables Cloud injects for attached resources
   (`DB_*`, Redis, filesystem and bucket credentials). `APP_URL` points at the Cloud URL until cutover.
8. **Database**: copies the database from the Forge server straight into Cloud, then compares row counts table by
   table. A temporary SSH key is added for this and removed afterwards, and a Laravel MySQL cluster's public endpoint
   is only switched on during the copy.
9. **Files**: copies `storage/app/public` to a public bucket and the rest of `storage/app` to a private one. Running it
   again only copies what changed.
10. **Deploy**: deploys and checks the app answers on its `*.laravel.cloud` URL.
11. **Cutover**: puts the Forge site into maintenance mode, copies the database again, syncs new files, adds your
    domains to Cloud and shows the DNS records to set, then checks them until they verify. You can stop while DNS
    propagates and run the command again later.

Progress is saved in `.laravel-cloud.json` (no secrets are written there), so you can stop at any point and pick up
where you left off.

| Option | Description |
|--------|-------------|
| `--step=<name>` | Run or re-run one step: `setup`, `site`, `inspect`, `report`, `config`, `provision`, `env`, `database`, `files`, `deploy` or `cutover` |
| `--fresh` | Forget saved progress and start again (Cloud resources already created are kept) |
| `--dry-run` | Inspect the site, show the report and print the config without creating or changing anything |

### What it won't migrate

- Custom Nginx directives, Forge redirect rules and basic auth. They're listed in the report; recreate them in your app.
- Scheduled jobs other than `schedule:run`. Move them into your app's scheduler.
- Daemons that aren't artisan commands, and Reverb (use Cloud's managed WebSocket servers instead).
- Deploy script lines that don't map to a build or deploy command, or that contain credentials.
- Anything your code writes to the local disk at runtime. Cloud's filesystem is rebuilt on every deploy and isn't
  shared between replicas, so the report points at code using the `local` or `public` disks.
- Sites that aren't Laravel, repositories not on GitHub, and SQLite or SQL Server databases.

### Rolling back

Nothing on Forge is ever deleted, and after cutover the Forge site stays in maintenance mode. To roll back, point your
DNS back at the Forge server and take the site out of maintenance mode (in Forge, or `php artisan up` on the server).
Data written to Cloud after the cutover isn't copied back. Lowering your DNS TTL before cutover makes both the move and
a rollback quicker.

## Configuration File

The `config/cloud.php` file allows you to define:

- **Application settings**: Name, repository, region
- **Environments**: Production, staging, or custom environments
- **PHP/Node versions**: Specify versions for each environment
- **Build & deploy commands**: Custom build and deployment scripts
- **Server configuration**: Octane, request timeout, per-instance hibernation
- **Network settings**: Caching, response headers, firewall settings
- **Instances**: Compute resources with scaling configuration
- **Background processes**: Queue workers and custom processes
- **Domains**: Custom domains with SSL and WWW redirects
- **Environment variables**: Global and per-environment variables
- **Databases, caches and buckets**: Created if missing and attached to the environments that list them

`cloud:deploy` exits with a non-zero status when a deployment fails, so it can gate a CI job.

See the published config file for detailed examples and documentation.

## State Management

The package maintains a `.laravel-cloud.json` file in your project root to track deployed infrastructure IDs. This
allows subsequent deployments to update existing resources rather than creating duplicates.

Add it to git and share it with your team or CI tool.

## Requirements

- PHP 8.2+
- Laravel 11.x, 12.x or 13.x

## License

MIT License. See [LICENSE](LICENSE) for details.
