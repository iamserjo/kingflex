<?php

declare(strict_types=1);

namespace App\Providers;

use App\Events\HtmlDomReady;
use App\Listeners\ProcessCrawledPage;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event to listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        // Process page when DOM is ready
        HtmlDomReady::class => [
            ProcessCrawledPage::class,
        ],
    ];

    /**
     * Register any events for your application.
     */
    public function boot(): void
    {
        parent::boot();

        // Disable automatic event discovery to prevent duplicate listener registration.
        $this->disableEventDiscovery();
    }

    /**
     * Determine if events and listeners should be automatically discovered.
     */
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
