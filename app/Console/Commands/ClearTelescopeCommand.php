<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Clear Telescope data to prevent UUID conflicts.
 */
class ClearTelescopeCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'telescope:clear
                            {--all : Clear all Telescope data}
                            {--older-than= : Clear entries older than X days (default: 7)}';

    /**
     * The console command description.
     */
    protected $description = 'Clear Telescope data to prevent UUID conflicts';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if ($this->option('all')) {
            return $this->clearAll();
        }

        $days = (int) ($this->option('older-than') ?: 7);

        return $this->clearOlderThan($days);
    }

    /**
     * Clear all Telescope data.
     */
    private function clearAll(): int
    {
        if (!$this->confirm('Are you sure you want to clear ALL Telescope data?')) {
            $this->info('Cancelled.');
            return self::SUCCESS;
        }

        $this->info('Clearing all Telescope data...');

        DB::table('telescope_entries')->truncate();
        DB::table('telescope_entries_tags')->truncate();
        DB::table('telescope_monitoring')->truncate();

        $this->info('✅ All Telescope data cleared.');

        return self::SUCCESS;
    }

    /**
     * Clear Telescope data older than specified days.
     */
    private function clearOlderThan(int $days): int
    {
        $this->info("Clearing Telescope entries older than {$days} days...");

        $date = now()->subDays($days);

        $deletedEntries = DB::table('telescope_entries')
            ->where('created_at', '<', $date)
            ->delete();

        $deletedTags = DB::table('telescope_entries_tags')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('telescope_entries')
                    ->whereColumn('telescope_entries.uuid', 'telescope_entries_tags.entry_uuid');
            })
            ->delete();

        $this->info("✅ Deleted {$deletedEntries} entries and {$deletedTags} orphaned tags.");

        return self::SUCCESS;
    }
}

