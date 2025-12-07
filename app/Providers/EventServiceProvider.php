<?php

declare(strict_types=1);

namespace App\Providers;

use App\Events\HtmlDomReady;
use App\Events\PageRawHtmlFetched;
use App\Events\PageRendered;
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
        // Process page when DOM is ready (after raw HTML fetch or JS rendering)
        HtmlDomReady::class => [
            ProcessCrawledPage::class,
        ],

        // These events are dispatched but handled by HtmlDomReady flow
        // They can be used for logging, metrics, or additional processing
        PageRawHtmlFetched::class => [
            // Add listeners here if needed
        ],

        PageRendered::class => [
            // Add listeners here if needed
        ],
    ];

    /**
     * Register any events for your application.
     */
    public function boot(): void
    {
        parent::boot();

        // Disable automatic event discovery to prevent duplicate listener registration.
        // We use manual registration via the $listen property only.
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

