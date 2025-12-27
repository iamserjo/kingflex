<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Domain;
use App\Models\Page;
use Illuminate\Database\Seeder;

class DomainSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $domain = Domain::updateOrCreate(
            ['domain' => 'ti.ua'],
            [
                'allowed_subdomains' => ['www'],
                'crawl_settings' => [
                    'name' => 'Техноеж',
                    'protocol' => 'https',
                    'delay_between_requests' => 200,
                    'max_pages_per_run' => 500,
                ],
                'is_active' => true,
            ]
        );

        $sitemapUrl = 'https://ti.ua/ua/sitemap.html';

        $domain->pages()->updateOrCreate(
            [
                'url_hash' => hash('sha256', $sitemapUrl),
            ],
            [
                'url' => $sitemapUrl,
                'depth' => 0,
                'inbound_links_count' => 0,
                'last_crawled_at' => null,
                'page_type' => Page::TYPE_OTHER,
                'metadata' => [
                    'seeded_as' => 'initial_sitemap',
                ],
            ],
        );
    }
}

