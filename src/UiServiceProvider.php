<?php

namespace HardImpact\Orbit\Ui;

use HardImpact\Orbit\Ui\Commands\UiCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class UiServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('orbit-ui')
            ->hasConfigFile()
            ->hasViews()
            ->hasMigration('create_orbit_ui_table')
            ->hasCommand(UiCommand::class);
    }
}
