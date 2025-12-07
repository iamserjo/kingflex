<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Crawler Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for the web crawler using spatie/crawler
    |
    */

    // Maximum number of concurrent connections
    'concurrency' => env('CRAWLER_CONCURRENCY', 10),

    // Delay between requests in milliseconds
    'delay_between_requests' => env('CRAWLER_DELAY', 100),

    // Maximum crawl depth (0 = unlimited)
    'max_depth' => env('CRAWLER_MAX_DEPTH', 0),

    // Maximum number of pages to crawl per domain (0 = unlimited)
    'max_pages_per_domain' => env('CRAWLER_MAX_PAGES', 0),

    // Maximum response size in bytes (2MB default)
    'max_response_size' => env('CRAWLER_MAX_RESPONSE_SIZE', 1024 * 1024 * 2),

    // User agent string
    'user_agent' => env('CRAWLER_USER_AGENT', 'MarketKing Crawler/1.0'),

    // Request timeout in seconds
    'timeout' => env('CRAWLER_TIMEOUT', 30),

    // Whether to respect robots.txt
    'respect_robots' => env('CRAWLER_RESPECT_ROBOTS', true),

    // Parseable MIME types
    'parseable_mime_types' => [
        'text/html',
        'text/plain',
    ],

    /*
    |--------------------------------------------------------------------------
    | Screenshot Settings
    |--------------------------------------------------------------------------
    */

    // Path to store screenshots (relative to storage/app)
    'screenshots_path' => env('CRAWLER_SCREENSHOTS_PATH', 'screenshots'),

    // Screenshot format (png or jpeg)
    'screenshot_format' => env('CRAWLER_SCREENSHOT_FORMAT', 'png'),

    // Screenshot quality (1-100, only for jpeg)
    'screenshot_quality' => env('CRAWLER_SCREENSHOT_QUALITY', 90),

    // Full page screenshot
    'screenshot_full_page' => env('CRAWLER_SCREENSHOT_FULL_PAGE', true),

    // Viewport width for screenshots
    'viewport_width' => env('CRAWLER_VIEWPORT_WIDTH', 1920),

    // Viewport height for screenshots
    'viewport_height' => env('CRAWLER_VIEWPORT_HEIGHT', 1080),

    /*
    |--------------------------------------------------------------------------
    | Browsershot Settings (Puppeteer)
    |--------------------------------------------------------------------------
    */

    // Path to Chrome/Chromium binary
    'chrome_path' => env('CHROME_PATH'),

    // Path to Node.js binary
    'node_path' => env('NODE_PATH'),

    // Path to NPM binary
    'npm_path' => env('NPM_PATH'),

    // Additional Puppeteer arguments
    'puppeteer_args' => [
        '--no-sandbox',
        '--disable-setuid-sandbox',
        '--disable-dev-shm-usage',
        '--disable-gpu',
    ],

    /*
    |--------------------------------------------------------------------------
    | Recrawl Intervals (in hours)
    |--------------------------------------------------------------------------
    |
    | Based on inbound_links_count, determines how often to recrawl pages
    |
    */

    'recrawl_intervals' => [
        // Pages with 100+ inbound links: recrawl every 1 hour
        'high_priority' => [
            'min_links' => 100,
            'interval_hours' => 1,
        ],
        // Pages with 10-99 inbound links: recrawl every 6 hours
        'medium_priority' => [
            'min_links' => 10,
            'interval_hours' => 6,
        ],
        // Pages with less than 10 inbound links: recrawl every 24 hours
        'low_priority' => [
            'min_links' => 0,
            'interval_hours' => 24,
        ],
    ],

    // Maximum pages to process per crawl:update run
    'max_pages_per_run' => env('CRAWLER_MAX_PAGES_PER_RUN', 100),

];

