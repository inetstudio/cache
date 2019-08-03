<?php

namespace InetStudio\CachePackage\Cache\Contracts\Services\Front;

use Illuminate\Support\Collection;
use League\Fractal\TransformerAbstract;

/**
 * Interface CacheServiceContract.
 */
interface CacheServiceContract
{
    /**
     * Кэшируем результаты запросов.
     *
     * @param  Collection  $items
     * @param  TransformerAbstract|array  $transformers
     * @param  array  $params
     * @param  array  $additionalCacheKeys
     * @param  bool  $returnKeys
     *
     * @return Collection
     */
    public function cacheItems(
        Collection $items,
        $transformers,
        array $params = [],
        array $additionalCacheKeys = [],
        bool $returnKeys = false
    ): Collection;

    /**
     * Получаем кэшированные данные по ключам.
     *
     * @param  Collection  $keys
     *
     * @return Collection
     */
    public function getCachedItems(Collection $keys): Collection;

    /**
     * Добавляем ключи в группу.
     *
     * @param  string  $groupKey
     * @param  array  $additionalCacheKeys
     */
    public function addKeysToCacheGroup(string $groupKey, array $additionalCacheKeys): void;

    /**
     * Очищаем кэш по ключам.
     *
     * @param $item
     */
    public function clearCacheKeys($item): void;

    /**
     * Очищаем кэш по группе ключей.
     *
     * @param  string  $groupKey
     */
    public function clearCacheGroup(string $groupKey): void;

    /**
     * Возвращаем ключи группы.
     *
     * @param  string  $groupKey
     *
     * @return array
     */
    public function getGroupCacheKeys(string $groupKey = ''): array;
}