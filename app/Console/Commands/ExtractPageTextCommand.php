<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * Command to extract text from a rendered web page using Playwright.
 * Uses headless Chromium to fully render JavaScript content before extraction.
 */
class ExtractPageTextCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'page:extract-text
                            {url : The URL to extract text from}
                            {--timeout=30000 : Page load timeout in milliseconds}
                            {--wait-for=networkidle : Wait until event (load, domcontentloaded, networkidle, commit)}
                            {--user-agent= : Custom user agent string}
                            {--json : Output as JSON with metadata}';

    /**
     * The console command description.
     */
    protected $description = 'Extract all text from a rendered web page using Playwright headless browser';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $url = $this->argument('url');
        $timeout = $this->option('timeout');
        $waitFor = $this->option('wait-for');
        $userAgent = $this->option('user-agent');
        $json = $this->option('json');

        // Validate URL
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            $this->error("Invalid URL: {$url}");
            return self::FAILURE;
        }

        // Build the command arguments
        $scriptPath = base_path('scripts/puppeteer-extract-text.js');

        if (!file_exists($scriptPath)) {
            $this->error('Puppeteer script not found at: ' . $scriptPath);
            return self::FAILURE;
        }

        $args = ['node', $scriptPath, $url];
        $args[] = "--timeout={$timeout}";
        $args[] = "--wait-for={$waitFor}";

        if ($userAgent) {
            $args[] = "--user-agent={$userAgent}";
        }

        if ($json) {
            $args[] = '--json';
        }

        $this->info("🌐 Extracting text from: {$url}");
        $this->info("⏳ Timeout: {$timeout}ms, Wait for: {$waitFor}");
        $this->newLine();

        // Run the Puppeteer script
        $process = new Process($args);
        $process->setTimeout((int) ($timeout / 1000) + 30); // Add buffer for browser startup
        
        // Stream output in real-time
        $process->run(function ($type, $buffer) {
            if ($type === Process::ERR) {
                $this->error($buffer);
            } else {
                $this->output->write($buffer);
            }
        });

        if (!$process->isSuccessful()) {
            $this->newLine();
            $this->error('Failed to extract text from page.');
            
            if ($process->getErrorOutput()) {
                $this->error($process->getErrorOutput());
            }
            
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}

