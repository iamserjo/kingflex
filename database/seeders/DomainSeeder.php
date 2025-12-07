<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Domain;
use Illuminate\Database\Seeder;

class DomainSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Domain::updateOrCreate(
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
    }
}

