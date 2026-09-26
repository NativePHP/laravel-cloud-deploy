<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use NativePhp\LaravelCloudDeploy\ForgeClient;
use NativePhp\LaravelCloudDeploy\Tests\Support\FakeApis;

test('the client sends the token and scopes requests to the organization', function () {
    FakeApis::fake();

    $servers = (new ForgeClient('forge-token', 'acme'))->listServers();

    expect($servers)->toHaveCount(1)
        ->and($servers[0]['attributes']['name'])->toBe('web-1');

    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://forge.laravel.com/api/orgs/acme/servers')
        && $request->hasHeader('Authorization', 'Bearer forge-token')
        && $request->hasHeader('Accept', 'application/json'));
});

test('list helpers follow the cursor in meta.next_cursor', function () {
    Http::fake(function (Request $request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return ($query['page']['cursor'] ?? null) === 'abc'
            ? Http::response(FakeApis::page([FakeApis::server(['name' => 'web-2'])]))
            : Http::response(FakeApis::page([FakeApis::server()], nextCursor: 'abc'));
    });

    $servers = (new ForgeClient('forge-token', 'acme'))->listServers();

    expect(array_map(fn ($server) => $server['attributes']['name'], $servers))->toBe(['web-1', 'web-2']);
    Http::assertSentCount(2);
});

test('hasValidToken is false for a 401', function () {
    Http::fake(['forge.laravel.com/api/orgs*' => Http::response(['message' => 'Unauthenticated.'], 401)]);

    expect((new ForgeClient('bad'))->hasValidToken())->toBeFalse();
});

test('hasValidToken rethrows a 403 so a missing scope is not mistaken for a bad token', function () {
    Http::fake(['forge.laravel.com/api/orgs*' => Http::response(['message' => 'Forbidden.'], 403)]);

    (new ForgeClient('no-scopes'))->hasValidToken();
})->throws(RequestException::class);

test('a 403 is explained in terms of token scopes', function () {
    Http::fake(['*' => Http::response(['message' => 'This action is unauthorized.'], 403)]);

    try {
        (new ForgeClient('token', 'acme'))->listServers();
    } catch (RequestException $e) {
        expect(ForgeClient::describeError($e))
            ->toContain('missing a scope')
            ->toContain('server:view')
            ->toContain('server:create-keys');

        return;
    }

    $this->fail('Expected a RequestException');
});

test('getIntegration returns null when Forge answers 404', function () {
    FakeApis::fake();

    $client = new ForgeClient('token', 'acme');

    expect($client->getIntegration('101', '202', 'octane')['data']['attributes']['enabled'])->toBe('false')
        ->and($client->getIntegration('101', '202', 'inertia'))->toBeNull();
});

test('reading a site returns its env, deploy script and background processes', function () {
    FakeApis::fake([
        'GET /servers/101/background-processes' => FakeApis::page([
            FakeApis::process('9', 'php8.3 /home/forge/example.com/artisan queue:work redis --queue=default'),
        ]),
    ]);

    $client = new ForgeClient('token', 'acme');

    expect($client->getEnvironmentFile('101', '202'))->toContain('APP_NAME=Shop')
        ->and($client->getDeploymentScript('101', '202'))->toContain('artisan migrate --force')
        ->and($client->listBackgroundProcesses('101', '202'))->toHaveCount(1);

    Http::assertSent(fn (Request $request) => str_contains(urldecode($request->url()), 'filter[site_id]=202'));
});

test('ssh keys are added for the site user and removed by id', function () {
    FakeApis::fake([
        'POST /servers/101/ssh-keys' => Http::response(null, 202),
        'GET /servers/101/ssh-keys' => FakeApis::page([
            FakeApis::resource('keys', '55', ['name' => 'laravel-cloud-migrate-1', 'user' => 'forge', 'status' => 'installed', 'created_by' => 1, 'created_at' => '', 'updated_at' => '']),
        ]),
        'DELETE /servers/101/ssh-keys/55' => Http::response(null, 202),
    ]);

    $client = new ForgeClient('token', 'acme');
    $client->addSshKey('101', 'laravel-cloud-migrate-1', 'ssh-ed25519 AAAA test', 'forge');
    $key = $client->findSshKeyByName('101', 'laravel-cloud-migrate-1');
    $client->deleteSshKey('101', $key['id']);

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && str_ends_with($request->url(), '/orgs/acme/servers/101/ssh-keys')
        && $request['name'] === 'laravel-cloud-migrate-1'
        && $request['key'] === 'ssh-ed25519 AAAA test'
        && $request['user'] === 'forge');

    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
        && str_ends_with($request->url(), '/orgs/acme/servers/101/ssh-keys/55'));
});

test('maintenance mode goes through the laravel-maintenance integration', function () {
    FakeApis::fake([
        'POST /servers/101/sites/202/integrations/laravel-maintenance' => Http::response(null, 202),
        'DELETE /servers/101/sites/202/integrations/laravel-maintenance' => Http::response(null, 202),
    ]);

    $client = new ForgeClient('token', 'acme');
    $client->enableMaintenanceMode('101', '202');
    $client->disableMaintenanceMode('101', '202');

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && str_ends_with($request->url(), '/integrations/laravel-maintenance')
        && $request['status'] === 503);

    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
        && str_ends_with($request->url(), '/integrations/laravel-maintenance'));
});
