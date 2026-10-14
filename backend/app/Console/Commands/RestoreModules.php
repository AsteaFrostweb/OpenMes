<?php

namespace App\Console\Commands;

use App\Services\ModuleManager;
use Illuminate\Console\Command;

/**
 * Puts back modules installed through the panel, at container start.
 *
 * modules/ and public/modules/ live in the image layer, so recreating the
 * container — every image upgrade — removed every uploaded module and its
 * frontend while the database still listed it as enabled. The archives are
 * kept on the storage volume; this extracts the missing ones and, with
 * --publish, republishes the frontend of every enabled module.
 */
class RestoreModules extends Command
{
    protected $signature = 'modules:restore {--publish : Also publish the frontend of every enabled module}';

    protected $description = 'Restore modules installed through the panel after the container was recreated';

    public function handle(ModuleManager $manager): int
    {
        $result = $manager->restore();

        foreach ($result['restored'] as $name) {
            $this->info("Restored module {$name}.");
        }

        foreach ($result['failed'] as $name => $error) {
            // A module that cannot come back must not keep the container from
            // starting — the rest of the application still has to run.
            $this->warn("Could not restore module {$name}: {$error}");
        }

        if ($this->option('publish')) {
            foreach ($manager->enabledNames() as $name) {
                if (is_dir(base_path("modules/{$name}"))) {
                    $manager->publishAssets($name);
                }
            }
        }

        return self::SUCCESS;
    }
}
