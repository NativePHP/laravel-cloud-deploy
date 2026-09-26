<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Laravel Cloud API Token
    |--------------------------------------------------------------------------
    |
    | Your Laravel Cloud API token, generated from your organization settings
    | at cloud.laravel.com. This token is used to authenticate all API
    | requests. Keep this secret and never commit it to version control.
    |
    */

    'token' => env('LARAVEL_CLOUD_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Application Configuration
    |--------------------------------------------------------------------------
    |
    | Configure your Laravel Cloud application. The repository should be in
    | "owner/repo" format. The region determines where your application will
    | be deployed.
    |
    | source_control is the provider the repository lives on: "github",
    | "gitlab", "gitlab_self_hosted" or "bitbucket". The provider must
    | already be connected to your Cloud organization.
    |
    | Supported regions:
    |   - "us-east-2"      (Ohio)
    |   - "us-east-1"      (N. Virginia)
    |   - "ca-central-1"   (Canada)
    |   - "eu-west-1"      (Ireland)
    |   - "eu-west-2"      (London)
    |   - "eu-central-1"   (Frankfurt)
    |   - "me-central-1"   (UAE)
    |   - "ap-southeast-1" (Singapore)
    |   - "ap-southeast-2" (Sydney)
    |   - "ap-northeast-1" (Tokyo)
    |
    */

    'application' => [
        'name' => env('APP_NAME', 'My Application'),
        'repository' => env('LARAVEL_CLOUD_REPOSITORY'),
        'source_control' => env('LARAVEL_CLOUD_SOURCE_CONTROL', 'github'),
        'region' => env('LARAVEL_CLOUD_REGION', 'us-east-2'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Environments
    |--------------------------------------------------------------------------
    |
    | Define each environment you want to deploy. Each environment has its
    | own branch, configuration, instances, and domains. Common setups
    | include "production" and "staging" environments.
    |
    */

    'environments' => [

        'production' => [

            /*
            |------------------------------------------------------------------
            | Branch Configuration
            |------------------------------------------------------------------
            |
            | The git branch to deploy for this environment. Push-to-deploy
            | will automatically deploy when changes are pushed to this branch.
            |
            */

            'branch' => 'main',
            'push_to_deploy' => true,

            /*
            |------------------------------------------------------------------
            | PHP & Node Configuration
            |------------------------------------------------------------------
            |
            | Supported PHP versions: "8.2:1", "8.3:1", "8.4:1", "8.5:1"
            | Supported Node versions: "20", "22", "24"
            |
            */

            'php' => '8.4:1',
            'node' => '20',

            /*
            |------------------------------------------------------------------
            | Build & Deploy Commands
            |------------------------------------------------------------------
            |
            | Commands executed during the build and deployment process.
            | Build commands run during the container build phase.
            | Deploy commands run after the deployment is live.
            |
            */

            'build_commands' => [
                'composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader',
                'npm ci --audit false',
                'npm run build',
            ],

            'deploy_commands' => [
                // 'php artisan migrate --force',
            ],

            /*
            |------------------------------------------------------------------
            | Server Configuration
            |------------------------------------------------------------------
            |
            | Configure how your application handles HTTP requests.
            |
            | octane: Enable Laravel Octane for high-performance serving
            | timeout: Request timeout in seconds (5-60)
            |
            | Hibernation is set per instance, see "hibernation_timeout" below.
            |
            */

            'octane' => false,
            'timeout' => 30,

            /*
            |------------------------------------------------------------------
            | Vanity Domain
            |------------------------------------------------------------------
            |
            | Enable the free *.laravel.cloud vanity domain for this environment.
            |
            */

            'vanity_domain' => true,

            /*
            |------------------------------------------------------------------
            | Network & Security Settings
            |------------------------------------------------------------------
            */

            'network' => [
                'cache_strategy' => 'default',
                'purge_cache_on_deploy' => true,

                'response_headers' => [
                    'frame' => 'deny',           // deny, sameorigin, or all
                    'content_type' => 'nosniff', // nosniff or none
                    // 'robots_tag' => 'noindex, nofollow', // or 'index, follow'
                    'hsts' => [
                        'enabled' => true,
                        'max_age' => 31536000,         // 1 year
                        'include_subdomains' => true,
                        'preload' => true,
                    ],
                ],

                'firewall' => [
                    'block_path' => false,
                    'browser_integrity_check' => false,
                ],
            ],

            /*
            |------------------------------------------------------------------
            | Instances (Compute Resources)
            |------------------------------------------------------------------
            |
            | Define the compute instances for this environment. Each instance
            | can have its own size, scaling configuration, and background
            | processes like queue workers.
            |
            | Sizes: "flex-256mb", "flex-512mb", "flex-1gb", etc.
            |
            | Note: Cloud automatically provisions a default app instance
            | (named "App", flex-512mb) with every new environment, and an
            | environment can only have one app-type instance.
            |
            | New instances can be of type "service" (workers) or "managed_queue".
            | The type can't be changed after the instance is created.
            |
            | Scaling types:
            |   - "none"   : Fixed number of replicas
            |   - "custom" : Scale between min_replicas and max_replicas
            |   - "auto"   : Auto-scale based on CPU/memory thresholds
            |               (min/max replicas are not sent for this type)
            |
            | hibernation_timeout: Minutes idle before the instance hibernates.
            | Set it to null to turn hibernation off, or leave it out to keep
            | whatever is set in Cloud. Only applied to existing instances.
            |
            */

            'instances' => [

                'App' => [
                    'type' => 'app',
                    'size' => 'flex-512mb',

                    'scaling' => [
                        'type' => 'none',
                        'min_replicas' => 1,
                        'max_replicas' => 1,
                    ],

                    'scheduler' => false,

                    // 'hibernation_timeout' => null,

                    /*
                    |--------------------------------------------------------------
                    | Background Processes (Queue Workers)
                    |--------------------------------------------------------------
                    |
                    | Define queue workers and custom background processes.
                    |
                    | Worker types:
                    |   - "worker" : Laravel queue worker
                    |   - "custom" : Custom artisan command
                    |
                    */

                    'processes' => [

                        'default-worker' => [
                            'type' => 'worker',
                            'processes' => 2,
                            'queue' => [
                                'connection' => 'redis',
                                'queues' => ['default'],
                                'tries' => 3,
                                'backoff' => 30,
                                'timeout' => 60,
                                'sleep' => 3,
                                'rest' => 0,
                                'force' => false,
                            ],
                        ],

                        // Example: High-priority queue worker
                        // 'high-priority-worker' => [
                        //     'type' => 'worker',
                        //     'processes' => 1,
                        //     'queue' => [
                        //         'connection' => 'redis',
                        //         'queues' => ['high', 'default'],
                        //         'tries' => 3,
                        //         'backoff' => 10,
                        //         'timeout' => 120,
                        //     ],
                        // ],

                        // Example: Custom background process
                        // 'websocket-server' => [
                        //     'type' => 'custom',
                        //     'processes' => 1,
                        //     'command' => 'php artisan reverb:start',
                        // ],

                    ],
                ],

                // Example: Managed queue. Cloud runs the workers, scales them
                // with the queue depth and down to zero when idle, and sets
                // QUEUE_CONNECTION=cloud. The instance name is the queue name
                // (3-40 characters). Needs Laravel 11.55, 12.63 or 13.19+ and
                // aws/aws-sdk-php. Sizes: "mq.flex.256mb" up to "mq.flex.2gb"
                // (jobs up to 90 seconds) or "mq.pro.256mb" up to "mq.pro.8gb".
                // 'default' => [
                //     'type' => 'managed_queue',
                //     'size' => 'mq.flex.256mb',
                //     'scaling' => ['type' => 'custom', 'min_replicas' => 0, 'max_replicas' => 3],
                // ],

                // Example: Dedicated worker instance (separate from web)
                // 'worker' => [
                //     'type' => 'service',
                //     'size' => 'flex-512mb',
                //     'scaling' => [
                //         'type' => 'auto',
                //         'min_replicas' => 1,
                //         'max_replicas' => 10,
                //         'cpu_threshold' => 80,
                //         'memory_threshold' => 80,
                //     ],
                //     'scheduler' => true,
                //     'processes' => [
                //         'queue-worker' => [
                //             'type' => 'worker',
                //             'processes' => 5,
                //             'queue' => [
                //                 'connection' => 'redis',
                //                 'queues' => ['default', 'emails', 'notifications'],
                //                 'tries' => 3,
                //                 'timeout' => 300,
                //             ],
                //         ],
                //     ],
                // ],

            ],

            /*
            |------------------------------------------------------------------
            | Custom Domains
            |------------------------------------------------------------------
            |
            | Configure custom domains for this environment. Each domain
            | will be automatically provisioned with SSL certificates.
            |
            | WWW redirect options:
            |   - "root_to_www" : example.com → www.example.com
            |   - "www_to_root" : www.example.com → example.com
            |   - null          : No redirect
            |
            | Redirect and wildcard settings only apply when the domain is
            | created. After that the API only lets you change the
            | verification_method ("pre_verification" or "real_time").
            |
            */

            'domains' => [
                // 'example.com' => [
                //     'www_redirect' => 'www_to_root',
                //     'wildcard' => false,
                // ],
            ],

        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Environment Variables
    |--------------------------------------------------------------------------
    |
    | Define environment variables to sync to Laravel Cloud. Variables can
    | be defined globally (applied to all environments) or per-environment.
    |
    | IMPORTANT: Sensitive values should use env() to avoid committing
    | secrets to version control. These are synced during deployment.
    |
    */

    'variables' => [

        // Global variables (applied to all environments)
        'global' => [
            'APP_NAME' => env('APP_NAME'),
            'APP_DEBUG' => 'false',
            'LOG_CHANNEL' => 'stack',
        ],

        // Per-environment overrides
        'production' => [
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Database Clusters
    |--------------------------------------------------------------------------
    |
    | Describe the database clusters for your application. Clusters are
    | shared across environments. cloud:deploy creates each cluster if it
    | doesn't exist, creates a database (schema) in it for every listed
    | environment and attaches it, which makes Cloud inject the DB_*
    | variables.
    |
    | "environments" is either a list of environment names (each gets a
    | database named after the environment) or a map of environment name
    | to database name, e.g. ['production' => 'laravel'].
    |
    | "name" is optional and defaults to the key. Leave "version" out to
    | use the newest version Cloud offers.
    |
    | Supported types:
    |   - "laravel_mysql"             (Laravel MySQL)
    |   - "neon_serverless_postgres"  (Laravel Serverless Postgres, Neon)
    |   - "aws_rds_mysql"             (AWS RDS MySQL)
    |   - "aws_rds_postgres"          (AWS RDS Postgres)
    |
    | Each type has its own versions and config fields. GET /databases/types
    | lists them. The old versioned types (e.g. "laravel_mysql_8") are
    | retired but still accepted.
    |
    */

    'databases' => [

        // 'main' => [
        //     'type' => 'neon_serverless_postgres',
        //     'version' => '...', // one of the versions from GET /databases/types
        //     'region' => env('LARAVEL_CLOUD_REGION', 'us-east-2'),
        //
        //     // Serverless configuration (Neon Postgres)
        //     'config' => [
        //         'cu_min' => 0.25,        // Minimum compute units
        //         'cu_max' => 1,           // Maximum compute units
        //         'suspend_seconds' => 300, // Suspend after idle (0-604800)
        //         'retention_days' => 7,   // Backup retention (0-30)
        //     ],
        //
        //     // Environments to attach a database in this cluster to
        //     'environments' => ['production'],
        // ],

        // Example: Laravel MySQL configuration
        // 'mysql' => [
        //     'type' => 'laravel_mysql',
        //     'version' => '...', // one of the versions from GET /databases/types
        //     'region' => 'us-east-2',
        //     'config' => [
        //         'size' => 'mysql-flex-512mb',
        //         'storage' => 5,           // GB (5-1000)
        //         'is_public' => false,
        //         'uses_scheduled_snapshots' => true,
        //         'retention_days' => 7,
        //     ],
        //     'environments' => ['production'],
        // ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Caches
    |--------------------------------------------------------------------------
    |
    | Caches are created if they don't exist and attached to the listed
    | environments. Cloud then injects REDIS_HOST, REDIS_PASSWORD and
    | CACHE_STORE. An environment can only have one cache.
    |
    | Types: "laravel_valkey", "upstash_redis", "aws_elasticache_redis",
    | "aws_elasticache_valkey". GET /caches/types lists sizes per type.
    |
    */

    'caches' => [

        // 'cache' => [
        //     'type' => 'laravel_valkey',
        //     'size' => 'valkey-flex-250mb',
        //     'region' => env('LARAVEL_CLOUD_REGION', 'us-east-2'),
        //     'auto_upgrade_enabled' => true,
        //     'is_public' => false,
        //     'environments' => ['production'],
        // ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Object Storage Buckets
    |--------------------------------------------------------------------------
    |
    | Buckets are created with a read/write access key if they don't exist
    | and attached to the listed environments as a filesystem disk. Your
    | application needs league/flysystem-aws-s3-v3 to use them.
    |
    | visibility: "private" or "public" (set once, for the whole bucket)
    | jurisdiction: "default", "eu" or "us"
    | disk: the Storage disk name the bucket is available as
    | default: whether it becomes the default disk (FILESYSTEM_DISK)
    |
    */

    'buckets' => [

        // 'files' => [
        //     'visibility' => 'private',
        //     'jurisdiction' => 'default',
        //     'disk' => 's3',
        //     'default' => true,
        //     'environments' => ['production'],
        // ],

    ],

];
