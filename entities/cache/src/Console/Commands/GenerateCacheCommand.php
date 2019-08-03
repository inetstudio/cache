<?php

namespace InetStudio\CachePackage\Cache\Console\Commands;

use Illuminate\Console\Command;
use InetStudio\CachePackage\Cache\Contracts\Console\Commands\GenerateCacheCommandContract;

/**
 * Class GenerateCacheCommand.
 */
class GenerateCacheCommand extends Command implements GenerateCacheCommandContract
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cache:generate';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate app cache';

    /**
     * Execute the console command.
     */
    public function handle()
    {
    }
}
