<?php

declare(strict_types=1);

namespace NativePhp\LaravelCloudDeploy\Migration\Support;

/**
 * Maps Forge's repository providers to Cloud's source control providers.
 */
class SourceControl
{
    /**
     * Forge provider => Cloud source_control_provider_type.
     */
    public const PROVIDERS = [
        'github' => 'github',
        'gitlab' => 'gitlab',
        'bitbucket' => 'bitbucket',
        'gitlab-custom' => 'gitlab_self_hosted',
    ];

    /**
     * The Cloud provider for a Forge provider, or null when Cloud can't deploy from it.
     */
    public static function fromForge(?string $provider): ?string
    {
        return self::PROVIDERS[$provider] ?? null;
    }

    public static function label(?string $cloudProvider): string
    {
        return match ($cloudProvider) {
            'github' => 'GitHub',
            'gitlab' => 'GitLab',
            'gitlab_self_hosted' => 'self-hosted GitLab',
            'bitbucket' => 'Bitbucket',
            default => 'your Git provider',
        };
    }

    /**
     * How to connect a provider to Cloud. The Cloud API can't do this.
     *
     * @return array<int, string>
     */
    public static function connectHelp(?string $cloudProvider): array
    {
        $label = self::label($cloudProvider);

        return match ($cloudProvider) {
            'github' => [
                'In Laravel Cloud, open Account settings > Source control and connect GitHub.',
                'GitHub will ask you to install the Laravel Cloud app. Give it access to the repository you deploy from:',
                'https://github.com/apps/laravel-cloud-app/installations/select_target',
            ],
            'gitlab_self_hosted' => [
                'Deploying from self-hosted GitLab is only available on Laravel Cloud Private Cloud.',
                'See https://cloud.laravel.com/docs/private-cloud/self-hosted-gitlab to connect your GitLab instance.',
            ],
            null => [
                'In Laravel Cloud, open Account settings > Source control and connect the provider your repository is on (GitHub, GitLab or Bitbucket).',
                'The Git account you connect needs access to the repository.',
            ],
            default => [
                "In Laravel Cloud, open Account settings > Source control and connect {$label}.",
                "The {$label} account you connect needs access to the repository you deploy from.",
            ],
        };
    }
}
