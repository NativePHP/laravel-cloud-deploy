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
    | Configure your Laravel Cloud application. The repository should match
    | your GitHub repository in "owner/repo" format. The region determines
    | where your application will be deployed.
    |
    | Supported regions:
    |   - "us-east-2"      (Ohio)
    |   - "us-east-1"      (N. Virginia)
    |   - "eu-west-2"      (London)
    |   - "eu-central-1"   (Frankfurt)
    |   - "ap-southeast-1" (Singapore)
    |   - "ap-southeast-2" (Sydney)
    |
    */

    'application' => [
        'name' => env('APP_NAME', 'My Application'),
        'repository' => env('LARAVEL_CLOUD_REPOSITORY'),
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
            | Supported PHP versions: "8.2:1", "8.3:1", "8.4:1"
            | Supported Node versions: "20", "22"
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
            | web_server: Use traditional PHP-FPM (true) or Octane (false)
            | octane: Enable Laravel Octane for high-performance serving
            | hibernation: Enable sleep mode when idle to reduce costs
            | timeout: Request timeout in seconds
            |
            */

            'web_server' => false,
            'octane' => false,
            'hibernation' => false,
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
                    'frame' => 'deny',           // deny, sameorigin, or null
                    'content_type' => 'nosniff', // nosniff or null
                    'hsts' => [
                        'enabled' => true,
                        'max_age' => 31536000,         // 1 year
                        'include_subdomains' => true,
                        'preload' => true,
                    ],
                ],

                'firewall' => [
                    'rate_limit_level' => 'challenge', // challenge, block, or null
                    'under_attack_mode' => false,
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
            | Sizes: "flex.c-1vcpu-256mb", "flex.c-1vcpu-512mb", etc.
            |
            | Scaling types:
            |   - "none"   : Fixed number of replicas
            |   - "manual" : Manual scaling
            |   - "auto"   : Auto-scale based on CPU/memory thresholds
            |
            */

            'instances' => [

                'App' => [
                    'type' => 'app',
                    'size' => 'flex.g-1vcpu-512mb',

                    'scaling' => [
                        'type' => 'none',
                        'min_replicas' => 1,
                        'max_replicas' => 1,
                    ],

                    'scheduler' => false,

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

                // Example: Dedicated worker instance (separate from web)
                // 'worker' => [
                //     'type' => 'service',
                //     'size' => 'flex.c-1vcpu-512mb',
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

];
