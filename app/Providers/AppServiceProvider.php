<?php

namespace App\Providers;

use App\Contracts\Repositories\EmailRepositoryInterface;
use App\Contracts\Repositories\FileRepositoryInterface;
use App\Contracts\Services\EmailMigrationServiceInterface;
use App\Contracts\Services\S3ServiceInterface;
use App\Repositories\EmailRepository;
use App\Repositories\FileRepository;
use App\Services\EmailMigrationService;
use App\Services\S3Service;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * All of the container bindings that should be registered.
     *
     * @var array<string, string>
     */
    public array $bindings = [
        S3ServiceInterface::class => S3Service::class,
        EmailRepositoryInterface::class => EmailRepository::class,
        FileRepositoryInterface::class => FileRepository::class,
        EmailMigrationServiceInterface::class => EmailMigrationService::class,
    ];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Singleton bindings for services that should be shared
        $this->app->singleton(S3ServiceInterface::class, S3Service::class);
        $this->app->singleton(EmailMigrationServiceInterface::class, EmailMigrationService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
