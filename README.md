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
- **Databases**: Database clusters and which environments get a database in them

See the published config file for detailed examples and documentation.

### Databases

`cloud:deploy` owns the `databases` section. For each environment it deploys, it finds the cluster by name and only
creates it when no cluster has that name. It then makes sure the environment's database exists in the cluster (named
after the environment unless you give it a name) and attaches it.

```php
'databases' => [
    'main' => [
        'type' => 'laravel_mysql',
        'version' => '8.4',
        'region' => 'us-east-2',
        'config' => [
            'size' => 'mysql-flex-512mb',
            'storage' => 5,
            'is_public' => false,
            'uses_scheduled_snapshots' => true,
            'retention_days' => 7,
        ],
        // production gets a database called "production", staging one called "stage_db"
        'environments' => ['production', 'staging' => 'stage_db'],
    ],
],
```

It never detaches, drops or recreates a cluster or database. If an environment already has a different database
attached, it's left alone and you get a warning. The settings of an existing cluster aren't changed.

## State Management

The package maintains a `.laravel-cloud.json` file in your project root to track deployed infrastructure IDs. This
allows subsequent deployments to update existing resources rather than creating duplicates.

Add it to git and share it with your team or CI tool. The file is only written when an ID in it changes, so a deploy
that finds nothing new leaves it alone. Deployment IDs and timestamps aren't kept in it.

If the file is lost, the next run rebuilds it by looking everything up: the application by repository, and
environments, instances, domains, database clusters and databases by name. Background processes have no name in
Cloud, so they're matched on their settings (type, then queue connection and queues for workers, or the command for
custom processes). A lost state file doesn't lead to duplicate workers.

## Requirements

- PHP 8.2+
- Laravel 11.x or 12.x

## License

MIT License. See [LICENSE](LICENSE) for details.
