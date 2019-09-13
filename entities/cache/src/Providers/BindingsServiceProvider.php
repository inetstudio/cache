<?php

namespace InetStudio\CachePackage\Cache\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Contracts\Support\DeferrableProvider;

/**
 * Class BindingsServiceProvider.
 */
class BindingsServiceProvider extends ServiceProvider implements DeferrableProvider
{
    /**
     * @var array
     */
    public $bindings = [
        'InetStudio\CachePackage\Cache\Contracts\Console\Commands\GenerateCacheCommandContract' => 'InetStudio\CachePackage\Cache\Console\Commands\GenerateCacheCommand',
        'InetStudio\CachePackage\Cache\Contracts\Serializers\CacheDataArraySerializerContract' => 'InetStudio\CachePackage\Cache\Serializers\CacheDataArraySerializer',
        'InetStudio\CachePackage\Cache\Contracts\Services\Front\CacheServiceContract' => 'InetStudio\CachePackage\Cache\Services\Front\CacheService',
    ];

    /**
     * Получить сервисы от провайдера.
     *
     * @return array
     */
    public function provides()
    {
        return array_keys($this->bindings);
    }
}
