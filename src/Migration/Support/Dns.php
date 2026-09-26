<?php

declare(strict_types=1);

namespace NativePhp\LaravelCloudDeploy\Migration\Support;

use Illuminate\Support\Carbon;
use NativePhp\LaravelCloudDeploy\Migration\MigrationContext;

use function Laravel\Prompts\note;

/**
 * DNS advice and lookups for the cutover.
 */
class Dns
{
    /**
     * Resolves a host name to its records. Swapped out in tests.
     *
     * @var (callable(string): array<int, array<string, mixed>>)|null
     */
    public static $resolver = null;

    /**
     * Show the "lower your TTL a day ahead" advice and remember when it was
     * first shown, so the cutover can say how long ago that was.
     */
    public static function adviseTtl(MigrationContext $context): void
    {
        note(implode(PHP_EOL, [
            'Plan ahead: lower the TTL on your domain\'s DNS records (to 60 or 300 seconds) at least 24 hours before you cut over.',
            'Resolvers cache records for as long as the old TTL, so a lower TTL makes the switch to Cloud, and any rollback, reach everyone quickly.',
        ]));

        if ($context->get('dns.ttl_advised_at') === null) {
            $context->put('dns.ttl_advised_at', now()->toIso8601String());
        }
    }

    /**
     * How long ago the TTL advice was first shown, or null if never.
     */
    public static function ttlAdvisedAgo(MigrationContext $context): ?Carbon
    {
        $at = $context->get('dns.ttl_advised_at');

        return $at ? Carbon::parse($at) : null;
    }

    /**
     * Look up the current A, AAAA and CNAME records for each domain and its
     * www. name, so they can be put back if the migration is rolled back.
     *
     * @param  array<int, string>  $domains
     * @return array<int, array{name: string, type: string, value: string, ttl: int|null}>
     */
    public static function snapshot(array $domains): array
    {
        $resolver = static::$resolver ?? fn (string $host) => @dns_get_record($host, DNS_A | DNS_AAAA | DNS_CNAME) ?: [];
        $hosts = [];

        foreach ($domains as $domain) {
            $hosts[] = $domain;

            if (! str_starts_with($domain, 'www.')) {
                $hosts[] = "www.{$domain}";
            }
        }

        $records = [];

        foreach (array_unique($hosts) as $host) {
            foreach ($resolver($host) as $record) {
                $value = $record['ip'] ?? $record['ipv6'] ?? $record['target'] ?? null;

                if ($value === null) {
                    continue;
                }

                $records[] = [
                    'name' => (string) ($record['host'] ?? $host),
                    'type' => (string) $record['type'],
                    'value' => (string) $value,
                    'ttl' => isset($record['ttl']) ? (int) $record['ttl'] : null,
                ];
            }
        }

        return $records;
    }
}
