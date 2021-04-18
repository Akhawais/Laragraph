<?php


namespace Scriptle\Laragraph;


use Scriptle\Laragraph\LaragraphCacheCommand;
use Scriptle\Laragraph\LaragraphClearCommand;
use Illuminate\Support\Facades\Route;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;

class LaragraphServiceProvider extends ServiceProvider
{
    public const HOME = '/home';

    public function register()
    {
        parent::register();
    }

    public function boot()
    {
        $this->routes(function () {
            Route::middleware('web')
                ->prefix(config('laragraph.prefix'))
                ->group(function () {
                    $schemas = config('laragraph.schemas');
                    foreach ($schemas ?? [] as $name => $schema) {
                        Route::any($name, 'Scriptle\\Laragraph\\LaragraphController@ingest')
                            ->middleware($schema['middleware']);
                    }
                });
        });

        if ($this->app->runningInConsole()) {
            $this->commands([
                LaragraphCacheCommand::class,
                LaragraphClearCommand::class,
            ]);
        }

        $this->publishes([
            __DIR__.'/../config/laragraph.php' => config_path('laragraph.php'),
        ]);
    }
}
