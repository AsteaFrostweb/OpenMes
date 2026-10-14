<?php

namespace Tests\Feature;

use App\Services\ModuleManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Modules installed through the panel survive a recreated container, and an
 * upgrade of an enabled module gets its new frontend.
 *
 * modules/ and public/modules/ are in the image layer; only storage/ is a
 * volume. Before this, `docker compose pull && up -d` removed every uploaded
 * module while the database still listed it as enabled, and reinstalling an
 * enabled module never published its frontend — every page of it rendered
 * "Page unavailable" until someone disabled and re-enabled it.
 */
class ModulePersistenceTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    private ModuleManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/openmes-persist-'.uniqid();
        foreach (['modules', 'packages', 'public'] as $dir) {
            mkdir("{$this->root}/{$dir}", 0777, true);
        }

        $this->app->usePublicPath("{$this->root}/public");

        $this->manager = new class("{$this->root}/modules", "{$this->root}/packages") extends ModuleManager
        {
            public function __construct(string $modules, string $packages)
            {
                parent::__construct();
                $this->modulesPath = $modules;
                $this->packagesPath = $packages;
            }
        };
        $this->app->instance(ModuleManager::class, $this->manager);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->root));
        parent::tearDown();
    }

    private function moduleZip(string $version = '1.0.0', string $bundle = '/* 1.0.0 */'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mod').'.zip';
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('Sample/module.json', json_encode([
            'name' => 'Sample',
            'version' => $version,
            'provider' => 'Modules\\Sample\\Providers\\SampleServiceProvider',
        ]));
        $zip->addFromString('Sample/public/Sample.js', $bundle);
        $zip->close();

        return $path;
    }

    private function enable(string $name): void
    {
        DB::table('system_settings')->updateOrInsert(
            ['key' => 'modules_enabled'],
            ['value' => json_encode([$name]), 'description' => 'Enabled module names'],
        );
    }

    /** What recreating the container does: the image layer is fresh, storage/ is not. */
    private function recreateContainer(): void
    {
        exec('rm -rf '.escapeshellarg("{$this->root}/modules/Sample").' '.escapeshellarg("{$this->root}/public/modules"));
    }

    public function test_install_keeps_the_archive_on_storage(): void
    {
        $this->manager->installFromZip($this->moduleZip());

        $this->assertFileExists("{$this->root}/packages/Sample.zip");
    }

    public function test_restore_brings_a_module_back_after_the_container_is_recreated(): void
    {
        $this->manager->installFromZip($this->moduleZip());
        $this->enable('Sample');
        $this->recreateContainer();

        $this->artisan('modules:restore', ['--publish' => true])
            ->expectsOutput('Restored module Sample.')
            ->assertSuccessful();

        $this->assertFileExists("{$this->root}/modules/Sample/module.json");
        $this->assertFileExists("{$this->root}/public/modules/Sample.js");
    }

    public function test_restore_leaves_a_present_module_alone(): void
    {
        // It may have been updated in place since it was uploaded.
        $this->manager->installFromZip($this->moduleZip('1.0.0'));
        file_put_contents("{$this->root}/modules/Sample/module.json", json_encode(['name' => 'Sample', 'version' => '1.1.0']));

        $this->assertSame([], $this->manager->restore()['restored']);
        $this->assertSame('1.1.0', json_decode(file_get_contents("{$this->root}/modules/Sample/module.json"), true)['version']);
    }

    public function test_reinstalling_an_enabled_module_publishes_its_new_frontend(): void
    {
        $this->manager->installFromZip($this->moduleZip('1.0.0', '/* 1.0.0 */'));
        $this->enable('Sample');
        $this->manager->publishAssets('Sample');

        $this->manager->installFromZip($this->moduleZip('1.1.0', '/* 1.1.0 */'));

        $this->assertSame('/* 1.1.0 */', file_get_contents("{$this->root}/public/modules/Sample.js"));
    }

    public function test_installing_a_disabled_module_does_not_publish_it(): void
    {
        $this->manager->installFromZip($this->moduleZip());

        $this->assertFileDoesNotExist("{$this->root}/public/modules/Sample.js");
    }

    public function test_a_module_updating_itself_can_hand_over_its_archive(): void
    {
        $this->manager->installFromZip($this->moduleZip('1.0.0'));

        $this->manager->rememberPackage('Sample', $this->moduleZip('1.2.0'));
        $this->recreateContainer();
        $this->manager->restore();

        $this->assertSame('1.2.0', json_decode(file_get_contents("{$this->root}/modules/Sample/module.json"), true)['version']);
    }

    public function test_uninstall_forgets_the_archive(): void
    {
        $this->manager->installFromZip($this->moduleZip());

        $this->manager->uninstall('Sample');
        $this->recreateContainer();

        $this->assertSame([], $this->manager->restore()['restored']);
        $this->assertFileDoesNotExist("{$this->root}/packages/Sample.zip");
    }

    public function test_a_package_that_no_longer_installs_does_not_stop_the_start(): void
    {
        file_put_contents("{$this->root}/packages/Broken.zip", 'not a zip');

        $this->artisan('modules:restore')
            ->expectsOutputToContain('Could not restore module Broken')
            ->assertSuccessful();
    }

    public function test_remember_package_ignores_an_unsafe_name(): void
    {
        $this->manager->rememberPackage('../evil', $this->moduleZip());

        $this->assertSame([], glob("{$this->root}/packages/*"));
        $this->assertFileDoesNotExist("{$this->root}/evil.zip");
    }
}
