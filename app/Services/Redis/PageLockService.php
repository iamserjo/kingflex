<?php

declare(strict_types=1);

namespace App\Services\Redis;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Service for managing Redis-based page processing locks.
 * 
 * Each stage (extract, recap, embed) uses separate lock keys to allow
 * parallel processing of different stages for different pages.
 * 
 * Lock key format: page:lock:{stage}:{page_id}
 * Lock value: Unix timestamp when lock was acquired
 */
class PageLockService
{
    /**
     * Lock timeout in seconds. Locks older than this are considered stale.
     */
    private const LOCK_TIMEOUT_SECONDS = 10;

    /**
     * Lock key prefix.
     */
    private const LOCK_PREFIX = 'page:lock';

    /**
     * Attempt to acquire a lock for a page at a specific stage.
     * 
     * If the lock exists but is stale (older than LOCK_TIMEOUT_SECONDS),
     * it will be overwritten with a new lock.
     *
     * @param int $pageId The page ID to lock
     * @param string $stage The processing stage (extract, recap, embed)
     * @return bool True if lock was acquired, false if page is locked by another process
     */
    public function acquireLock(int $pageId, string $stage): bool
    {
        $key = $this->getLockKey($pageId, $stage);
        $now = time();

        // Check if lock exists
        $existingLock = Redis::get($key);

        if ($existingLock !== null && $existingLock !== false) {
            $lockTimestamp = (int) $existingLock;
            $lockAge = $now - $lockTimestamp;

            // If lock is still fresh, cannot acquire
            if ($lockAge < self::LOCK_TIMEOUT_SECONDS) {
                Log::debug('🔒 [PageLock] Lock is active, skipping', [
                    'page_id' => $pageId,
                    'stage' => $stage,
                    'lock_age_seconds' => $lockAge,
                ]);
                return false;
            }

            // Lock is stale, we can take it over
            Log::info('🔒 [PageLock] Taking over stale lock', [
                'page_id' => $pageId,
                'stage' => $stage,
                'stale_lock_age_seconds' => $lockAge,
            ]);
        }

        // Acquire lock with timestamp
        Redis::set($key, (string) $now);

        Log::debug('🔒 [PageLock] Lock acquired', [
            'page_id' => $pageId,
            'stage' => $stage,
            'timestamp' => $now,
        ]);

        return true;
    }

    /**
     * Release a lock for a page at a specific stage.
     *
     * @param int $pageId The page ID to unlock
     * @param string $stage The processing stage (extract, recap, embed)
     */
    public function releaseLock(int $pageId, string $stage): void
    {
        $key = $this->getLockKey($pageId, $stage);
        Redis::del($key);

        Log::debug('🔓 [PageLock] Lock released', [
            'page_id' => $pageId,
            'stage' => $stage,
        ]);
    }

    /**
     * Check if a page is locked at a specific stage.
     *
     * @param int $pageId The page ID to check
     * @param string $stage The processing stage (extract, recap, embed)
     * @return bool True if page is locked (and lock is not stale)
     */
    public function isLocked(int $pageId, string $stage): bool
    {
        $key = $this->getLockKey($pageId, $stage);
        $existingLock = Redis::get($key);

        if ($existingLock === null || $existingLock === false) {
            return false;
        }

        $lockTimestamp = (int) $existingLock;
        $lockAge = time() - $lockTimestamp;

        return $lockAge < self::LOCK_TIMEOUT_SECONDS;
    }

    /**
     * Get the Redis key for a page lock.
     *
     * @param int $pageId The page ID
     * @param string $stage The processing stage
     * @return string The Redis key
     */
    private function getLockKey(int $pageId, string $stage): string
    {
        return self::LOCK_PREFIX . ':' . $stage . ':' . $pageId;
    }

    /**
     * Get lock timeout in seconds.
     *
     * @return int Lock timeout in seconds
     */
    public function getLockTimeout(): int
    {
        return self::LOCK_TIMEOUT_SECONDS;
    }
}

