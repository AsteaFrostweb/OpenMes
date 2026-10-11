<?php

namespace Tests\Feature;

use App\Services\ModuleManager;
use Illuminate\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;
use Tests\TestCase;

/**
 * Enabled modules are loaded the way a real request loads them: from inside
 * AppServiceProvider::boot(), while the application is still booting.
 *
 * That timing is what the guard tests cannot see. They register into an
 * application that has already booted, where Laravel boots a provider the
 * moment it is registered. During a real boot it is booted later, from a list
 * keyed by class name — and with one guard per module, only the last module
 * enabled was ever booted: two modules installed, one of them silently without
 * routes or menus.
 */
class ModuleLoadingTest extends TestCase
{
    use RefreshDatabase;

    private string $modulesPath;

    protected function setUp(): void
    {
        parent::setUp();

        LoadingTestModule::reset();
        $this->modulesPath = sys_get_temp_dir().'/openmes-module-loading-'.uniqid();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->modulesPath);

        parent::tearDown();
    }

    /** @param  array<string, class-string>  $modules  name => provider */
    private function install(array $modules): void
    {
        foreach ($modules as $name => $provider) {
            File::ensureDirectoryExists("{$this->modulesPath}/{$name}");
            File::put("{$this->modulesPath}/{$name}/module.json", json_encode([
                'name' => $name,
                'version' => '1.0.0',
                'provider' => $provider,
            ]));
        }

        DB::table('system_settings')->updateOrInsert(
            ['key' => 'modules_enabled'],
            ['value' => json_encode(array_keys($modules))],
        );
    }

    /** A fresh application, booted with the modules loaded mid-boot as in production. */
    private function bootApplication(): void
    {
        $manager = new class($this->modulesPath) extends ModuleManager
        {
            public function __construct(string $modulesPath)
            {
                parent::__construct();
                $this->modulesPath = $modulesPath;
            }
        };

        // Constructing an Application makes it the global container; the test's
        // own application has to be put back for everything after this.
        try {
            $app = new Application(base_path());
            // A failing module is reported through the exception handler, which
            // a real application always has.
            $app->instance(ExceptionHandler::class, $this->app->make(ExceptionHandler::class));
            $app->register(new LoadsModulesWhileBooting($app, $manager));
            $app->boot();
        } finally {
            Container::setInstance($this->app);
        }
    }

    public function test_every_enabled_module_is_booted_not_only_the_last(): void
    {
        $this->install([
            'First' => FirstLoadingTestProvider::class,
            'Second' => SecondLoadingTestProvider::class,
            'Third' => ThirdLoadingTestProvider::class,
        ]);

        $this->bootApplication();

        $this->assertSame(['First', 'Second', 'Third'], LoadingTestModule::$registered);
        $this->assertSame(['First', 'Second', 'Third'], LoadingTestModule::$booted);
    }

    public function test_a_module_that_throws_does_not_stop_the_next_from_booting(): void
    {
        $this->install([
            'Broken' => ThrowingLoadingTestProvider::class,
            'Second' => SecondLoadingTestProvider::class,
        ]);

        $this->bootApplication();

        $this->assertSame(['Second'], LoadingTestModule::$booted);
    }

    public function test_a_single_module_is_still_booted(): void
    {
        $this->install(['First' => FirstLoadingTestProvider::class]);

        $this->bootApplication();

        $this->assertSame(['First'], LoadingTestModule::$booted);
    }

    public function test_no_enabled_module_registers_nothing(): void
    {
        $this->bootApplication();

        $this->assertSame([], LoadingTestModule::$registered);
    }
}

/** Stands in for AppServiceProvider, which loads the modules from its boot(). */
class LoadsModulesWhileBooting extends ServiceProvider
{
    public function __construct($app, private ModuleManager $manager)
    {
        parent::__construct($app);
    }

    public function boot(): void
    {
        $this->manager->loadEnabled($this->app);
    }
}

/** What the module providers below did, in order. */
class LoadingTestModule
{
    /** @var list<string> */
    public static array $registered = [];

    /** @var list<string> */
    public static array $booted = [];

    public static function reset(): void
    {
        self::$registered = [];
        self::$booted = [];
    }
}

abstract class LoadingTestProvider extends ServiceProvider
{
    abstract protected function module(): string;

    public function register(): void
    {
        LoadingTestModule::$registered[] = $this->module();
    }

    public function boot(): void
    {
        LoadingTestModule::$booted[] = $this->module();
    }
}

class FirstLoadingTestProvider extends LoadingTestProvider
{
    protected function module(): string
    {
        return 'First';
    }
}

class SecondLoadingTestProvider extends LoadingTestProvider
{
    protected function module(): string
    {
        return 'Second';
    }
}

class ThirdLoadingTestProvider extends LoadingTestProvider
{
    protected function module(): string
    {
        return 'Third';
    }
}

class ThrowingLoadingTestProvider extends LoadingTestProvider
{
    protected function module(): string
    {
        return 'Broken';
    }

    public function boot(): void
    {
        throw new \RuntimeException('module boot failed');
    }
}
