<?php

declare(strict_types=1);

namespace NativePhp\LaravelCloudDeploy;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * A thin, read-mostly client for the organization-scoped Forge API.
 *
 * Only the endpoints the Forge migration needs are covered. The only
 * writes are adding and removing a temporary SSH key and switching the
 * site's maintenance mode on and off.
 */
class ForgeClient
{
    /**
     * Where users create a Forge API token.
     */
    public const TOKEN_URL = 'https://forge.laravel.com/profile/api';

    /**
     * The token scopes the migration uses, and why.
     *
     * @var array<string, string>
     */
    public const SCOPES = [
        'organization:view' => 'list your organizations',
        'server:view' => 'read servers, sites, .env, deploy script, processes, scheduled jobs and databases',
        'site:meta' => 'read the site\'s domains',
        'site:manage-redirects' => 'read redirect rules',
        'site:manage-security' => 'read basic auth rules',
        'server:create-keys' => 'add a temporary SSH key for copying data',
        'server:delete-keys' => 'remove that SSH key again',
        'site:manage-commands' => 'put the site into maintenance mode during cutover',
    ];

    protected string $baseUrl = 'https://forge.laravel.com/api';

    protected PendingRequest $http;

    public function __construct(
        protected string $token,
        protected ?string $organization = null,
    ) {
        $this->http = Http::baseUrl($this->baseUrl)
            ->withToken($this->token)
            ->acceptJson()
            ->contentType('application/json')
            // The API allows 60 requests a minute; back off and retry if we hit it.
            ->retry(3, fn (int $attempt, \Throwable $e) => $this->retryDelay($e), function (\Throwable $e) {
                return $e instanceof RequestException && $e->response->status() === 429;
            })
            ->throw();
    }

    /**
     * Use the given organization slug for every org-scoped request.
     */
    public function forOrganization(string $organization): static
    {
        $this->organization = $organization;

        return $this;
    }

    /**
     * The organization slug requests are scoped to.
     */
    public function organization(): string
    {
        if (! $this->organization) {
            throw new \LogicException('No Forge organization selected.');
        }

        return $this->organization;
    }

    /**
     * Fetch every item of a cursor-paginated list endpoint.
     *
     * @param  array<string, mixed>  $query
     * @return array<int, array<string, mixed>>
     */
    public function all(string $path, array $query = []): array
    {
        $items = [];
        $query['page[size]'] ??= 100;

        while (true) {
            $response = $this->http->get($path, $query)->json();

            array_push($items, ...($response['data'] ?? []));

            $cursor = $response['meta']['next_cursor'] ?? null;

            if (! $cursor) {
                return $items;
            }

            $query['page[cursor]'] = $cursor;
        }
    }

    /**
     * Check whether Forge accepts the token.
     *
     * Returns false for a 401. Anything else (including a 403 for a
     * missing scope) is rethrown so it isn't mistaken for a bad token.
     */
    public function hasValidToken(): bool
    {
        try {
            $this->http->get('/orgs', ['page[size]' => 1]);

            return true;
        } catch (RequestException $e) {
            if ($e->response->status() === 401) {
                return false;
            }

            throw $e;
        }
    }

    /**
     * List the organizations the token can access.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listOrganizations(): array
    {
        return $this->all('/orgs');
    }

    /**
     * List the organization's servers.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listServers(): array
    {
        return $this->all($this->org('/servers'));
    }

    /**
     * Get a server.
     *
     * @return array<string, mixed>
     */
    public function getServer(string $serverId): array
    {
        return $this->http->get($this->org("/servers/{$serverId}"))->json();
    }

    /**
     * List the sites on a server.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listSites(string $serverId): array
    {
        return $this->all($this->org("/servers/{$serverId}/sites"));
    }

    /**
     * Get a site.
     *
     * @return array<string, mixed>
     */
    public function getSite(string $siteId): array
    {
        return $this->http->get($this->org("/sites/{$siteId}"))->json();
    }

    /**
     * Get the contents of a site's .env file.
     */
    public function getEnvironmentFile(string $serverId, string $siteId): string
    {
        return (string) $this->http->get($this->site($serverId, $siteId, '/environment'))
            ->json('data.attributes.content', '');
    }

    /**
     * Get a site's deployment script.
     */
    public function getDeploymentScript(string $serverId, string $siteId): string
    {
        return (string) $this->http->get($this->site($serverId, $siteId, '/deployments/script'))
            ->json('data.attributes.content', '');
    }

    /**
     * Get a site's Nginx configuration.
     */
    public function getNginxConfig(string $serverId, string $siteId): string
    {
        return (string) $this->http->get($this->site($serverId, $siteId, '/nginx'))
            ->json('data.attributes.content', '');
    }

    /**
     * List a server's background processes (Supervisor programs).
     *
     * Forge's API has no separate queue worker resource: workers, Horizon,
     * Octane and Reverb all show up here as processes.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listBackgroundProcesses(string $serverId, ?string $siteId = null): array
    {
        $query = $siteId ? ['filter[site_id]' => $siteId] : [];

        return $this->all($this->org("/servers/{$serverId}/background-processes"), $query);
    }

    /**
     * List the scheduled jobs attached to a site.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listSiteScheduledJobs(string $serverId, string $siteId): array
    {
        return $this->all($this->site($serverId, $siteId, '/scheduled-jobs'));
    }

    /**
     * List every scheduled job on a server.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listServerScheduledJobs(string $serverId): array
    {
        return $this->all($this->org("/servers/{$serverId}/scheduled-jobs"));
    }

    /**
     * List the database schemas on a server.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listDatabaseSchemas(string $serverId): array
    {
        return $this->all($this->org("/servers/{$serverId}/database/schemas"));
    }

    /**
     * List a site's domains (primary and aliases).
     *
     * @return array<int, array<string, mixed>>
     */
    public function listDomains(string $serverId, string $siteId): array
    {
        return $this->all($this->site($serverId, $siteId, '/domains'));
    }

    /**
     * List a site's redirect rules.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listRedirectRules(string $serverId, string $siteId): array
    {
        return $this->all($this->site($serverId, $siteId, '/redirect-rules'));
    }

    /**
     * List a site's security (HTTP basic auth) rules.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listSecurityRules(string $serverId, string $siteId): array
    {
        return $this->all($this->site($serverId, $siteId, '/security-rules'));
    }

    /**
     * Get one of a site's integrations (horizon, octane, reverb,
     * laravel-scheduler, laravel-maintenance, pulse, inertia).
     *
     * Returns null when Forge answers 404, which older sites do for
     * integrations that were never set up.
     *
     * @return array<string, mixed>|null
     */
    public function getIntegration(string $serverId, string $siteId, string $integration): ?array
    {
        try {
            return $this->http->get($this->site($serverId, $siteId, "/integrations/{$integration}"))->json();
        } catch (RequestException $e) {
            if ($e->response->status() === 404) {
                return null;
            }

            throw $e;
        }
    }

    /**
     * Add a public SSH key to a server for the given user.
     *
     * The request is processed asynchronously and the API returns no body,
     * so find the key afterwards with findSshKeyByName().
     */
    public function addSshKey(string $serverId, string $name, string $publicKey, ?string $user = null): void
    {
        $this->http->post($this->org("/servers/{$serverId}/ssh-keys"), array_filter([
            'name' => $name,
            'key' => $publicKey,
            'user' => $user,
        ], fn ($value) => $value !== null));
    }

    /**
     * Find an SSH key on a server by name.
     *
     * @return array<string, mixed>|null
     */
    public function findSshKeyByName(string $serverId, string $name): ?array
    {
        foreach ($this->all($this->org("/servers/{$serverId}/ssh-keys")) as $key) {
            if (($key['attributes']['name'] ?? null) === $name) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Remove an SSH key from a server.
     */
    public function deleteSshKey(string $serverId, string $keyId): void
    {
        $this->http->delete($this->org("/servers/{$serverId}/ssh-keys/{$keyId}"));
    }

    /**
     * Put a site into Laravel maintenance mode (php artisan down).
     */
    public function enableMaintenanceMode(string $serverId, string $siteId, ?string $secret = null, int $status = 503): void
    {
        $this->http->post($this->site($serverId, $siteId, '/integrations/laravel-maintenance'), [
            'status' => $status,
            'secret' => $secret,
        ]);
    }

    /**
     * Take a site out of Laravel maintenance mode (php artisan up).
     */
    public function disableMaintenanceMode(string $serverId, string $siteId): void
    {
        $this->http->delete($this->site($serverId, $siteId, '/integrations/laravel-maintenance'));
    }

    /**
     * Explain a failed request in terms a user can act on.
     */
    public static function describeError(RequestException $e): string
    {
        $status = $e->response->status();
        $message = $e->response->json('message') ?: $e->getMessage();

        return match ($status) {
            401 => 'Forge rejected the API token (401). It may have expired or been revoked.',
            403 => "Forge refused the request (403): {$message}. The token is probably missing a scope. It needs: "
                .implode(', ', array_keys(self::SCOPES)).'.',
            404 => "Forge couldn't find that resource (404): {$message}",
            default => "Forge API error ({$status}): {$message}",
        };
    }

    protected function org(string $path): string
    {
        return '/orgs/'.$this->organization().$path;
    }

    protected function site(string $serverId, string $siteId, string $path): string
    {
        return $this->org("/servers/{$serverId}/sites/{$siteId}{$path}");
    }

    /**
     * Milliseconds to wait before retrying a rate-limited request.
     */
    protected function retryDelay(\Throwable $e): int
    {
        if ($e instanceof RequestException) {
            $retryAfter = (int) $e->response->header('Retry-After');

            if ($retryAfter > 0) {
                return $retryAfter * 1000;
            }
        }

        return 5000;
    }
}
