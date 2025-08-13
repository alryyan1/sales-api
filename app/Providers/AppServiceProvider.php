<?php
namespace App\Providers;
use App\Services\WhatsAppService;
use App\Services\SettingsService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
        $this->app->singleton(WhatsAppService::class,function(){
            return new \App\Services\WhatsAppService();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Apply timezone from DB settings, fallback to config
        try {
            $settings = app(SettingsService::class)->getAll();
            $tz = $settings['timezone'] ?? config('app.timezone', 'Africa/Khartoum');
            if (is_string($tz) && $tz) {
                config(['app.timezone' => $tz]);
                date_default_timezone_set($tz);
            }
        } catch (\Throwable $e) {
            // In early boot or during install, settings table may not exist
            $tz = config('app.timezone', 'Africa/Khartoum');
            date_default_timezone_set($tz);
        }
    }
}
