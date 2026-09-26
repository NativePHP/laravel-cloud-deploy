<?php

declare(strict_types=1);

namespace NativePhp\LaravelCloudDeploy\Migration\Remote;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use NativePhp\LaravelCloudDeploy\Migration\MigrationContext;
use NativePhp\LaravelCloudDeploy\Migration\MigrationException;
use NativePhp\LaravelCloudDeploy\Migration\Support\Spinner;

/**
 * Gives the migration temporary SSH access to the Forge server.
 *
 * A fresh ed25519 key pair is made in a temp directory and added to the
 * server through the Forge API as the site's user. close() removes it
 * again. The key is recorded as pending cleanup the moment it's created,
 * so a run that crashes removes it the next time it starts.
 */
class SshAccess
{
    public const CLEANUP = 'ssh_key';

    /**
     * Seconds between checks while waiting for the key to be installed.
     */
    public static int $pollSeconds = 5;

    /**
     * How many times to check before giving up.
     */
    public static int $attempts = 24;

    public function __construct(
        protected MigrationContext $context,
    ) {}

    public function open(): RemoteShell
    {
        $inspection = $this->context->inspection();
        $server = $inspection['server'];
        $user = $inspection['site']['user'];

        if (! $server['ip_address']) {
            throw new MigrationException('Forge doesn\'t list a public IP address for this server, so it can\'t be reached over SSH.');
        }

        $directory = sys_get_temp_dir().'/laravel-cloud-migrate-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($directory, 0700);
        $keyPath = "{$directory}/id_ed25519";
        $name = 'laravel-cloud-migrate-'.date('YmdHis');

        $this->context->registerCleanup(self::CLEANUP, [
            'server_id' => $server['id'],
            'name' => $name,
            'directory' => $directory,
        ]);

        Process::run(['ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-C', $name, '-f', $keyPath])->throw();

        if (! File::exists("{$keyPath}.pub")) {
            throw new MigrationException('ssh-keygen didn\'t create a key pair.');
        }

        $this->context->forge()->addSshKey($server['id'], $name, trim(File::get("{$keyPath}.pub")), $user);

        Spinner::run(fn () => $this->waitForKey($server['id'], $name), 'Waiting for Forge to install a temporary SSH key...');

        $shell = new SshShell($server['ip_address'], $user, $server['ssh_port'], $keyPath, "{$directory}/known_hosts");

        Spinner::run(fn () => $this->waitForConnection($shell), "Connecting to {$shell->destination()}...");

        return $shell;
    }

    /**
     * Remove the temporary key from Forge and delete it locally.
     */
    public static function close(MigrationContext $context): void
    {
        $record = $context->cleanup(self::CLEANUP);

        if (! $record) {
            return;
        }

        try {
            $key = $context->forge()->findSshKeyByName($record['server_id'], $record['name']);

            if ($key) {
                $context->forge()->deleteSshKey($record['server_id'], (string) $key['id']);
            }
        } finally {
            File::deleteDirectory($record['directory']);
            $context->clearCleanup(self::CLEANUP);
        }
    }

    protected function waitForKey(string $serverId, string $name): void
    {
        for ($attempt = 1; $attempt <= static::$attempts; $attempt++) {
            $key = $this->context->forge()->findSshKeyByName($serverId, $name);
            $status = strtolower((string) ($key['attributes']['status'] ?? ''));

            if ($key && in_array($status, ['installed', 'active', 'enabled', ''], true)) {
                return;
            }

            if (str_contains($status, 'fail')) {
                throw new MigrationException("Forge couldn't install the temporary SSH key (status: {$status}).");
            }

            Sleep::for(static::$pollSeconds)->seconds();
        }

        throw new MigrationException('Timed out waiting for Forge to install the temporary SSH key.', [
            'Check the server is connected in Forge and try again.',
        ]);
    }

    protected function waitForConnection(RemoteShell $shell): void
    {
        $result = null;

        for ($attempt = 1; $attempt <= static::$attempts; $attempt++) {
            $result = $shell->run('echo connected', timeout: 30);

            if ($result->successful() && str_contains($result->output(), 'connected')) {
                return;
            }

            Sleep::for(static::$pollSeconds)->seconds();
        }

        throw new MigrationException("Couldn't SSH into {$shell->destination()}: ".trim((string) $result?->errorOutput()), [
            'Make sure port 22 (or your custom SSH port) is open to this machine.',
        ]);
    }
}
