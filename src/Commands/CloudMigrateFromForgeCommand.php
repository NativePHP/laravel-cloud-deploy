<?php

declare(strict_types=1);

namespace NativePhp\LaravelCloudDeploy\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\RequestException;
use NativePhp\LaravelCloudDeploy\CloudState;
use NativePhp\LaravelCloudDeploy\ForgeClient;
use NativePhp\LaravelCloudDeploy\Migration\Cleanup;
use NativePhp\LaravelCloudDeploy\Migration\MigrationContext;
use NativePhp\LaravelCloudDeploy\Migration\MigrationException;
use NativePhp\LaravelCloudDeploy\Migration\Steps\CutoverStep;
use NativePhp\LaravelCloudDeploy\Migration\Steps\DatabaseStep;
use NativePhp\LaravelCloudDeploy\Migration\Steps\DeployStep;
use NativePhp\LaravelCloudDeploy\Migration\Steps\EnvironmentVariablesStep;
use NativePhp\LaravelCloudDeploy\Migration\Steps\FilesStep;
use NativePhp\LaravelCloudDeploy\Migration\Steps\GenerateConfigStep;
use NativePhp\LaravelCloudDeploy\Migration\Steps\InspectStep;
use NativePhp\LaravelCloudDeploy\Migration\Steps\PickSiteStep;
use NativePhp\LaravelCloudDeploy\Migration\Steps\PreflightStep;
use NativePhp\LaravelCloudDeploy\Migration\Steps\ProvisionStep;
use NativePhp\LaravelCloudDeploy\Migration\Steps\ReportStep;
use NativePhp\LaravelCloudDeploy\Migration\Steps\Step;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\select;
use function Laravel\Prompts\warning;

class CloudMigrateFromForgeCommand extends Command
{
    protected $signature = 'cloud:migrate-from-forge
                            {--step= : Run (or re-run) a single step by name}
                            {--fresh : Forget saved progress and start again}
                            {--dry-run : Inspect the site and show the config without creating or changing anything}';

    protected $description = 'Move a Laravel site from Forge to Laravel Cloud, step by step';

    /**
     * The project the migration reads and writes (config/cloud.php, .env).
     * Defaults to the app's base path; tests point it somewhere else.
     */
    public static ?string $projectPath = null;

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $context = new MigrationContext($this, new CloudState, static::$projectPath ?? base_path(), $dryRun);
        $steps = $this->steps();

        intro($dryRun ? 'Forge to Laravel Cloud migration (dry run)' : 'Forge to Laravel Cloud migration');

        try {
            $only = $this->option('step');

            if ($only !== null && ! isset($steps[$only])) {
                error("There is no step called \"{$only}\". Steps: ".implode(', ', array_keys($steps)));

                return self::FAILURE;
            }

            if (! $this->handleSavedProgress($context, $steps, $only)) {
                return self::SUCCESS;
            }

            // Setup always runs first: every other step needs the API clients.
            if (! $this->runStep($context, $steps['setup'], 1, count($steps))) {
                return self::SUCCESS;
            }

            if (! $dryRun) {
                foreach (Cleanup::runPending($context) as $message) {
                    info($message);
                }
            }

            foreach ($steps as $name => $step) {
                if ($name === 'setup') {
                    continue;
                }

                if ($only !== null && $name !== $only) {
                    continue;
                }

                if ($dryRun && ! $step->runsInDryRun()) {
                    continue;
                }

                if ($only === null && ! $dryRun && $context->get("steps.{$name}") !== null) {
                    continue;
                }

                $position = array_search($name, array_keys($steps), true) + 1;

                if (! $this->runStep($context, $step, $position, count($steps))) {
                    return self::SUCCESS;
                }

                if ($context->pause) {
                    outro('Stopped here. Run php artisan cloud:migrate-from-forge again to carry on.');

                    return self::SUCCESS;
                }
            }

            outro(match (true) {
                $dryRun => 'Dry run finished. Nothing was created or changed.',
                $only !== null => "The {$only} step is done.",
                default => 'Migration complete.',
            });

            return self::SUCCESS;
        } catch (MigrationException $e) {
            error($e->getMessage());

            foreach ($e->hints as $hint) {
                $this->line('  '.$hint);
            }

            return self::FAILURE;
        } catch (RequestException $e) {
            error($this->describeRequestError($e));

            return self::FAILURE;
        }
    }

    /**
     * The steps in the order they run, keyed by name.
     *
     * @return array<string, Step>
     */
    protected function steps(): array
    {
        $database = new DatabaseStep;
        $files = new FilesStep;
        $deploy = new DeployStep;

        $steps = [
            new PreflightStep,
            new PickSiteStep,
            new InspectStep,
            new ReportStep,
            new GenerateConfigStep,
            new ProvisionStep,
            new EnvironmentVariablesStep,
            $database,
            $files,
            $deploy,
            new CutoverStep($database, $files, $deploy),
        ];

        return collect($steps)->keyBy(fn (Step $step) => $step->name())->all();
    }

    /**
     * Offer to resume or start over when there's saved progress.
     *
     * @param  array<string, Step>  $steps
     * @return bool False when the user chose to quit
     */
    protected function handleSavedProgress(MigrationContext $context, array $steps, ?string $only): bool
    {
        $done = $context->get('steps', []);

        if ($this->option('fresh')) {
            if ($done !== [] && ! confirm('Forget the saved migration progress and start again? Resources already created in Cloud are kept.', default: false)) {
                return false;
            }

            // Cloud resource IDs live outside the migration key, so they're kept.
            $context->forget('steps');
            $context->forget('inspection');
            $context->forget('plan');
            $context->forget('cutover');

            return true;
        }

        if ($only !== null || $done === [] || $context->dryRun) {
            return true;
        }

        $next = collect($steps)->first(fn (Step $step) => ! isset($done[$step->name()]));

        if ($next === null) {
            info('This site has already been migrated. Use --step=<name> to re-run a step, or --fresh to start again.');

            return false;
        }

        $choice = select(
            sprintf('You\'ve done %d of %d steps. Next up: %s.', count($done), count($steps), $next->title()),
            [
                'resume' => 'Carry on from there',
                'fresh' => 'Start again from the beginning',
                'quit' => 'Quit',
            ],
        );

        if ($choice === 'fresh') {
            $context->forget('steps');
            $context->forget('inspection');
            $context->forget('plan');
            $context->forget('cutover');
        }

        return $choice !== 'quit';
    }

    protected function runStep(MigrationContext $context, Step $step, int $position, int $total): bool
    {
        $this->newLine();
        $this->line("<options=bold>Step {$position} of {$total}: {$step->title()}</>");
        note($step->explanation());

        if (! $step->handle($context)) {
            warning('Stopped. Run php artisan cloud:migrate-from-forge again to pick up from this step.');

            return false;
        }

        $context->put("steps.{$step->name()}", now()->toIso8601String());

        return true;
    }

    protected function describeRequestError(RequestException $e): string
    {
        $host = (string) $e->response->effectiveUri()?->getHost();

        if (str_contains($host, 'forge.laravel.com')) {
            return ForgeClient::describeError($e);
        }

        $message = 'Laravel Cloud API error ('.$e->response->status().'): '.$e->response->json('message', $e->getMessage());

        foreach ($e->response->json('errors', []) as $field => $messages) {
            $message .= PHP_EOL."  {$field}: ".implode(' ', (array) $messages);
        }

        return $message;
    }
}
