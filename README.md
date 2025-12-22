# Laravel Cloud Deploy

Deploy Laravel applications to [Laravel Cloud](https://cloud.laravel.com) from the command line.

## Sponsor

This project is sponsored by [Bifrost](https://bifrost.nativephp.com) - the fastest way to compile and distribute your
NativePHP apps.

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
```

Generate an API token at: https://cloud.laravel.com/org/my-team/settings/api-tokens

### Supported Regions

- `us-east-2` (Ohio)
- `us-east-1` (N. Virginia)
- `eu-west-2` (London)
- `eu-central-1` (Frankfurt)
- `ap-southeast-1` (Singapore)
- `ap-southeast-2` (Sydney)

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
- **Server configuration**: Web server, Octane, hibernation settings
- **Network settings**: Caching, response headers, firewall rules
- **Instances**: Compute resources with scaling configuration
- **Background processes**: Queue workers and custom processes
- **Domains**: Custom domains with SSL and WWW redirects
- **Environment variables**: Global and per-environment variables

See the published config file for detailed examples and documentation.

## State Management

The package maintains a `.laravel-cloud.json` file in your project root to track deployed infrastructure IDs. This
allows subsequent deployments to update existing resources rather than creating duplicates.

Add it to git and share it with your team or CI tool.

## Requirements

- PHP 8.2+
- Laravel 11.x or 12.x

## License

MIT License. See [LICENSE](LICENSE) for details.
