<?php

namespace App\Providers;

use App\Events\SaleCreated;
use App\Listeners\PushSaleCreatedToRealtimeServer;
use App\Models\Payment;
use App\Models\Product;
use App\Models\PurchaseItem;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Observers\ProductObserver;
use App\Observers\SaleItemObserver;
use App\Observers\SaleObserver;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Event;

class EventServiceProvider extends ServiceProvider
{
    protected $observers = [
        PurchaseItem::class => [
            \App\Observers\PurchaseItemObserver::class,
        ],
        Payment::class => [
            \App\Observers\PaymentObserver::class,

        ],
        Product::class => [ProductObserver::class],
        SaleItem::class => [SaleItemObserver::class],
        Sale::class => [SaleObserver::class],
    ];
    /**
     * The event to listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        Registered::class => [
            SendEmailVerificationNotification::class,
        ],
        SaleCreated::class => [
            PushSaleCreatedToRealtimeServer::class,
        ],
    ];

    /**
     * Register any events for your application.
     */
    public function boot(): void
    {
        //
    }

    /**
     * Determine if events and listeners should be automatically discovered.
     */
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
