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

See the published config file for detailed examples and documentation.

## State Management

The package maintains a `.laravel-cloud.json` file in your project root to track deployed infrastructure IDs. This
allows subsequent deployments to update existing resources rather than creating duplicates.

Add it to git and share it with your team or CI tool. The file is only written when an ID in it changes, so a deploy
that finds nothing new leaves it alone. Deployment IDs and timestamps aren't kept in it.

If the file is lost, the next run rebuilds it by looking everything up: the application by repository, and
environments, instances and domains by name. Background processes have no name in Cloud, so they're matched on their
settings (type, then queue connection and queues for workers, or the command for custom processes). A lost state file
doesn't lead to duplicate workers.

## Requirements

- PHP 8.2+
- Laravel 11.x or 12.x

## License

MIT License. See [LICENSE](LICENSE) for details.
