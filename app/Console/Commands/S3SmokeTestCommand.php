<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class S3SmokeTestCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 's3:smoketest
        {--disk=s3 : Filesystem disk name (default: s3)}
        {--prefix=s3-smoketest : Key prefix/folder inside the bucket}
        {--size=1048576 : Payload size in bytes (default: 1MB)}
        {--keep : Do not delete the test object (for manual inspection)}
        {--no-throw : Do not force filesystem exceptions (use disk throw=false behavior)}
        {--endpoint= : Override S3 endpoint for this run (useful for S3-compatible providers)}
        {--path-style : Force path-style addressing (use_path_style_endpoint=true) for this run}';

    /**
     * @var string
     */
    protected $description = 'S3 smoke test: put/get/md5/delete and optional ETag/metadata checks';

    public function handle(): int
    {
        $diskName = (string) $this->option('disk');
        $prefix = trim((string) $this->option('prefix'), '/');
        $size = (int) $this->option('size');
        $keep = (bool) $this->option('keep');
        $noThrow = (bool) $this->option('no-throw');
        $endpointOverride = trim((string) $this->option('endpoint'));
        $forcePathStyle = (bool) $this->option('path-style');

        if ($size <= 0) {
            $this->error('❌ Option --size must be a positive integer (bytes).');
            return Command::FAILURE;
        }

        /** @var array<string, mixed>|null $diskConfig */
        $diskConfig = config("filesystems.disks.{$diskName}");
        if (!is_array($diskConfig)) {
            $this->error("❌ Disk [{$diskName}] is not configured in config/filesystems.php.");
            return Command::FAILURE;
        }

        $driver = (string) ($diskConfig['driver'] ?? '');
        if ($driver !== 's3') {
            $this->warn("⚠️ Disk [{$diskName}] driver is [{$driver}], not [s3]. I will still try put/get/delete via Storage.");
        }

        $mustForgetDisk = false;
        if ($endpointOverride !== '') {
            config(["filesystems.disks.{$diskName}.endpoint" => $endpointOverride]);
            $mustForgetDisk = true;
        }

        if ($forcePathStyle) {
            config(["filesystems.disks.{$diskName}.use_path_style_endpoint" => true]);
            $mustForgetDisk = true;
        }

        if (!$noThrow) {
            // Many disks in this project are configured with throw=false for app runtime.
            // For smoke-testing we want actionable errors, so force throw=true for this run.
            config(["filesystems.disks.{$diskName}.throw" => true]);
            $mustForgetDisk = true;
        }

        if ($mustForgetDisk) {
            Storage::forgetDisk($diskName);
        }

        /** @var array<string, mixed>|null $diskConfig */
        $diskConfig = config("filesystems.disks.{$diskName}") ?? [];
        $disk = Storage::disk($diskName);

        $this->info('🪣 S3 smoke test');
        $this->line("Disk: {$diskName}");
        $this->printDiskConfigSummary($diskName, $diskConfig);
        $this->line("Prefix: {$prefix}");
        $this->line("Payload size: {$size} bytes");
        if ($size > 5 * 1024 * 1024) {
            $this->warn('⚠️ size > 5MB: some S3 providers may use multipart upload; ETag may not equal MD5.');
        }
        $this->newLine();

        if ($driver === 's3') {
            $this->info('0) Connectivity / permissions check (headBucket)');
            try {
                /** @var \Illuminate\Filesystem\AwsS3V3Adapter $s3Disk */
                $s3Disk = $disk;
                $client = $s3Disk->getClient();
                $bucket = (string) ($diskConfig['bucket'] ?? '');

                if ($bucket === '') {
                    $this->warn('⚠️ Bucket is empty in config; skipping headBucket.');
                } else {
                    $client->headBucket(['Bucket' => $bucket]);
                    $this->info('✅ headBucket ok');
                }
            } catch (Throwable $e) {
                $this->warn('⚠️ headBucket failed: '.$e->getMessage());
            }
            $this->newLine();
        }

        $key = $prefix.'/'.now()->format('Ymd_His').'_'.Str::lower(Str::random(10)).'.bin';

        $payload = random_bytes($size);
        $md5Hex = md5($payload);
        $md5Base64 = base64_encode(md5($payload, true));

        $this->info('1) Upload (PUT)');
        $this->line("Key: {$key}");
        $this->line("MD5 (hex): {$md5Hex}");

        try {
            $putOk = $disk->put($key, $payload, [
                // If supported by the adapter/provider, this forces server-side MD5 validation.
                'ContentMD5' => $md5Base64,
                'ContentType' => 'application/octet-stream',
                // Convenience metadata for quick inspection via headObject/console UI.
                'Metadata' => [
                    'md5' => $md5Hex,
                    'created_at' => now()->toIso8601String(),
                    'source' => 'marketking:s3:smoketest',
                ],
            ]);
        } catch (Throwable $e) {
            $this->error('❌ PUT failed: '.$e->getMessage());
            return Command::FAILURE;
        }

        if ($putOk !== true) {
            $this->error('❌ PUT returned false.');
            return Command::FAILURE;
        }

        $this->info('✅ PUT ok');
        $this->newLine();

        $this->info('2) Existence check');
        try {
            $exists = $disk->exists($key);
        } catch (Throwable $e) {
            $this->error('❌ exists() failed: '.$e->getMessage());
            return Command::FAILURE;
        }

        if (!$exists) {
            $this->error('❌ Object not found right after PUT (exists() = false).');
            return Command::FAILURE;
        }
        $this->info('✅ exists() = true');
        $this->newLine();

        $this->info('3) Download (GET) + MD5 verification');
        try {
            $downloaded = $disk->get($key);
        } catch (Throwable $e) {
            $this->error('❌ GET failed: '.$e->getMessage());
            return Command::FAILURE;
        }

        $downloadedMd5Hex = md5($downloaded);
        $this->line("Downloaded bytes: ".strlen($downloaded));
        $this->line("Downloaded MD5: {$downloadedMd5Hex}");

        if (!hash_equals($md5Hex, $downloadedMd5Hex)) {
            $this->error('❌ MD5 mismatch after download.');
            return Command::FAILURE;
        }
        $this->info('✅ MD5 matches');
        $this->newLine();

        if ($driver === 's3') {
            $this->info('4) headObject (ETag/metadata) check');
            try {
                /** @var \Illuminate\Filesystem\AwsS3V3Adapter $s3Disk */
                $s3Disk = $disk;
                $client = $s3Disk->getClient();
                $bucket = (string) ($diskConfig['bucket'] ?? '');

                if ($bucket === '') {
                    $this->warn('⚠️ Bucket is empty in config; skipping headObject.');
                } else {
                    $head = $client->headObject([
                        'Bucket' => $bucket,
                        'Key' => $key,
                    ]);

                    $etag = isset($head['ETag']) ? trim((string) $head['ETag'], '"') : null;
                    $contentLength = (int) ($head['ContentLength'] ?? 0);
                    $metaMd5 = (string) (($head['Metadata']['md5'] ?? '') ?: '');

                    $this->line("ETag: ".($etag ?: '(none)'));
                    $this->line("ContentLength: {$contentLength}");
                    $this->line("Metadata.md5: ".($metaMd5 !== '' ? $metaMd5 : '(none)'));

                    if ($etag && hash_equals($md5Hex, $etag)) {
                        $this->info('✅ ETag equals MD5 (non-multipart typical case)');
                    } elseif ($etag) {
                        $this->warn('⚠️ ETag does not equal MD5 (multipart upload or S3-compatible provider behavior).');
                    }

                    if ($metaMd5 !== '' && hash_equals($md5Hex, $metaMd5)) {
                        $this->info('✅ Metadata md5 matches');
                    } elseif ($metaMd5 !== '') {
                        $this->warn('⚠️ Metadata md5 does not match');
                    }
                }
            } catch (Throwable $e) {
                $this->warn('⚠️ headObject check failed: '.$e->getMessage());
            }
            $this->newLine();
        }

        if ($keep) {
            $this->warn('⚠️ --keep specified: object is left in the bucket.');
            $this->line("Key: {$key}");
            return Command::SUCCESS;
        }

        $this->info('5) Delete (DELETE)');
        try {
            $deleted = $disk->delete($key);
        } catch (Throwable $e) {
            $this->error('❌ DELETE failed: '.$e->getMessage());
            return Command::FAILURE;
        }

        if ($deleted !== true) {
            $this->error('❌ DELETE returned false.');
            return Command::FAILURE;
        }
        $this->info('✅ DELETE ok');
        $this->newLine();

        $this->info('6) Verify object is gone');
        try {
            $existsAfter = $disk->exists($key);
        } catch (Throwable $e) {
            $this->warn('⚠️ exists() after delete failed: '.$e->getMessage());
            return Command::SUCCESS;
        }

        if ($existsAfter) {
            $this->warn('⚠️ exists() still true after delete (eventual consistency or provider behavior).');
            $this->line("Key: {$key}");
            return Command::SUCCESS;
        }

        $this->info('✅ Object removed');
        $this->newLine();
        $this->info('🎉 S3 smoke test passed');

        return Command::SUCCESS;
    }

    /**
     * Print non-sensitive S3 disk configuration summary.
     *
     * @param  array<string, mixed>  $diskConfig
     */
    private function printDiskConfigSummary(string $diskName, array $diskConfig): void
    {
        $bucket = (string) ($diskConfig['bucket'] ?? '');
        $region = (string) ($diskConfig['region'] ?? '');
        $endpoint = (string) ($diskConfig['endpoint'] ?? '');
        $usePathStyle = (bool) ($diskConfig['use_path_style_endpoint'] ?? false);
        $keyId = (string) ($diskConfig['key'] ?? '');

        $this->line("Bucket: ".($bucket !== '' ? $bucket : '(empty)'));
        $this->line("Region: ".($region !== '' ? $region : '(empty)'));
        $this->line("Endpoint: ".($endpoint !== '' ? $endpoint : '(empty)'));
        $this->line('Path-style: '.($usePathStyle ? 'true' : 'false'));

        if ($keyId !== '') {
            $suffix = strlen($keyId) > 6 ? substr($keyId, -6) : $keyId;
            $this->line("Key ID: ***{$suffix}");
        } else {
            $this->line('Key ID: (empty)');
        }

        $this->line('Throw exceptions: '.((bool) ($diskConfig['throw'] ?? false) ? 'true' : 'false')." (effective for this run: ".($this->option('no-throw') ? 'disk config' : 'forced true').')');
    }
}


