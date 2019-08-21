<?php

namespace InetStudio\CachePackage\Cache\Contracts\Services\Front;

use Illuminate\Support\Collection;
use League\Fractal\TransformerAbstract;
use InetStudio\CachePackage\Cache\Services\Front\CacheService;

/**
 * Interface CacheServiceContract.
 */
interface CacheServiceContract
{
    /**
     * Инициализация сервиса.
     *
     * @param $transformers
     * @param $keys
     * @param  array  $params
     *
     * @return CacheService
     */
    public function init($transformers, $keys, array $params = []);

    /**
     * Кэшируем результаты запросов.
     *
     * @param  mixed  $items
     * @param  bool  $returnKeys
     *
     * @return Collection
     */
    public function cacheItems($items, bool $returnKeys = false): Collection;

    /**
     * Получаем кэшированные данные по ключам.
     *
     * @param  mixed  $keys
     *
     * @return Collection
     */
    public function getCachedItems($keys): Collection;

    /**
     * Добавляем ключи в группу.
     *
     * @param  string  $groupKey
     * @param  mixed  $additionalCacheKeys
     */
    public function addKeysToCacheGroup(string $groupKey, $additionalCacheKeys);

    /**
     * Очищаем кэш по ключам.
     *
     * @param $item
     */
    public function clearCacheKeys($item);

    /**
     * Очищаем кэш по группе ключей.
     *
     * @param  string  $groupKey
     */
    public function clearCacheGroup(string $groupKey);

    /**
     * Возвращаем ключи группы.
     *
     * @param  string  $groupKey
     *
     * @return array
     */
    public function getGroupCacheKeys(string $groupKey = ''): array;

    /**
     * Генерируем ключ для кеширования.
     *
     * @param  bool  group
     * @param  mixed  $additionalData
     * @param  int  $level
     *
     * @return string
     */
    public static function generateCacheKey($group = false, $additionalData = '', int $level = 1): string;
}