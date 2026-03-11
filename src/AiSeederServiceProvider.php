<?php

declare(strict_types=1);

namespace Shennawy\AiSeeder;

use Illuminate\Support\ServiceProvider;
use Shennawy\AiSeeder\Console\Commands\AiSeedCommand;
use Shennawy\AiSeeder\Contracts\DataGeneratorInterface;
use Shennawy\AiSeeder\Contracts\RelationshipResolverInterface;
use Shennawy\AiSeeder\Contracts\SchemaAnalyzerInterface;

class AiSeederServiceProvider extends ServiceProvider
{
    /**
     * Register package services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/ai-seeder.php', 'ai-seeder');

        $this->app->singleton(SchemaAnalyzerInterface::class, SchemaAnalyzer::class);

        $this->app->singleton(DataGeneratorInterface::class, DataGenerator::class);

        $this->app->singleton(RelationshipResolverInterface::class, RelationshipResolver::class);

        $this->app->singleton(AiSeederOrchestrator::class, function ($app) {
            return new AiSeederOrchestrator(
                schemaAnalyzer: $app->make(SchemaAnalyzerInterface::class),
                relationshipResolver: $app->make(RelationshipResolverInterface::class),
                dataGenerator: $app->make(DataGeneratorInterface::class),
            );
        });
    }

    /**
     * Bootstrap package services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/ai-seeder.php' => config_path('ai-seeder.php'),
            ], 'ai-seeder-config');

            $this->commands([
                AiSeedCommand::class,
            ]);
        }
    }
}
