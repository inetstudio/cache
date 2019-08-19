<?php

namespace InetStudio\CachePackage\Cache\Services\Front;

use Illuminate\Support\Arr;
use League\Fractal\Manager;
use Illuminate\Cache\FileStore;
use Illuminate\Cache\RedisStore;
use League\Fractal\Resource\Item;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use League\Fractal\TransformerAbstract;
use Illuminate\Contracts\Container\BindingResolutionException;
use InetStudio\CachePackage\Cache\Contracts\Services\Front\CacheServiceContract;

/**
 * Trait CacheService.
 */
class CacheService implements CacheServiceContract
{
    /**
     * @var Manager
     */
    protected $manager;

    /**
     * @var
     */
    protected $cacheStore;

    /**
     * CacheService constructor.
     */
    public function __construct()
    {
        $this->cacheStore = Cache::getStore();
    }

    /**
     * Кэшируем результаты запросов.
     *
     * @param  mixed  $items
     * @param  TransformerAbstract|array  $transformers
     * @param  array  $params
     * @param  array  $additionalCacheKeys
     * @param  bool  $returnKeys
     *
     * @return Collection
     *
     * @throws BindingResolutionException
     */
    public function cacheItems(
        $items,
        $transformers,
        array $params = [],
        array $additionalCacheKeys = [],
        bool $returnKeys = false
    ): Collection {
        if (! is_iterable($items)) {
            $items = collect([$items]);
        }

        $data = [];

        if ($transformers instanceof TransformerAbstract) {
            $transformer = $transformers;
            $transformCacheKey = md5(get_class($transformer).json_encode(Arr::only($params, ['columns', 'includes'])));
        }

        $manager = $this->getManager($params);

        foreach ($items as $item) {
            if ($item) {
                if (is_array($transformers)) {
                    $interfaces = class_implements($item);

                    $interface = array_intersect(array_keys($transformers), $interfaces);
                    $interface = (count($interface) > 0) ? array_shift($interface) : null;

                    if (! $interface) {
                        continue;
                    }

                    $transformer = $transformers[$interface];

                    $transformCacheKey = md5(get_class($transformer).json_encode(Arr::only($params, ['columns', 'includes'])));
                }

                $objectKey = md5(get_class($item).$item->id);

                $cacheKey = 'transform_'.$transformCacheKey.'_'.$objectKey;

                $groupCacheKey = 'cacheKeys_'.$objectKey;

                $cacheKeys = array_merge([$cacheKey], $additionalCacheKeys);

                $this->addKeysToCacheGroup($groupCacheKey, $cacheKeys);
                $transformer->addCacheKeys($cacheKeys);

                $cachedItem = Cache::rememberForever($cacheKey, function () use ($item, $transformer, $manager) {
                    $resource = new Item($item, $transformer);

                    return $manager->createData($resource)->toArray();
                });

                $data[] = ($returnKeys) ? $cacheKey : $cachedItem;
            }
        }

        return collect($data);
    }

    /**
     * Получаем кэшированные данные по ключам.
     *
     * @param  Collection  $keys
     *
     * @return Collection
     */
    public function getCachedItems(Collection $keys): Collection
    {
        $items = collect();

        if ($keys->count() == 0) {
            return $items;
        }

        if ($this->cacheStore instanceof RedisStore) {
            $items = collect(Cache::many($keys->toArray()));
        } else {
            foreach ($keys as $key) {
                $item = Cache::get($key, []);
                $items->push($item);
            }
        }

        return $items;
    }

    /**
     * Добавляем ключи в группу.
     *
     * @param  string  $groupKey
     * @param  array  $additionalCacheKeys
     */
    public function addKeysToCacheGroup(string $groupKey, array $additionalCacheKeys): void
    {
        if (empty($additionalCacheKeys)) {
            return;
        }

        $keys = [];

        if ($this->cacheStore instanceof FileStore) {
            $keys = Cache::get($groupKey, []);
        }

        if (empty(array_diff($additionalCacheKeys, $keys))) {
            return;
        }

        $keys = array_unique(array_merge($keys, $additionalCacheKeys));

        if ($this->cacheStore instanceof RedisStore) {
            foreach ($keys as $key) {
                Cache::tags([$groupKey])->forever($key, $key);
            }
        } elseif ($this->cacheStore instanceof FileStore) {
            Cache::forget($groupKey);
            Cache::forever($groupKey, $keys);
        }
    }

    /**
     * Очищаем кэш по ключам.
     *
     * @param $item
     */
    public function clearCacheKeys($item): void
    {
        if ($item) {
            $cacheKey = 'cacheKeys_'.md5(get_class($item).$item->id);

            $this->clearCacheGroup($cacheKey);
        }
    }

    /**
     * Очищаем кэш по группе ключей.
     *
     * @param  string  $groupKey
     */
    public function clearCacheGroup(string $groupKey): void
    {
        if ($this->cacheStore instanceof RedisStore) {
            $prefix = $this->cacheStore->getPrefix();

            $tagKey = 'tag:'.$groupKey.':key';
            $setKey = Cache::get($tagKey);
            $setMembersKeys = $this->cacheStore->connection()->smembers($prefix.$setKey.':forever_ref');

            foreach ($setMembersKeys as $setMemberKey) {
                $setMemberKey = str_replace($prefix, '', $setMemberKey);
                $cacheKey = Cache::get($setMemberKey);

                Cache::forget($cacheKey);
            }

            Cache::forget($setKey);
            Cache::forget($tagKey);
            Cache::tags([$groupKey])->flush();
        } elseif ($this->cacheStore instanceof FileStore) {
            $keys = Cache::get($groupKey, []);

            foreach ($keys as $key) {
                Cache::forget($key);
            }

            Cache::forget($groupKey);
        }
    }

    /**
     * Инициализируем менеджера трансформации.
     *
     * @param  array  $params
     *
     * @return Manager
     *
     * @throws BindingResolutionException
     */
    protected function getManager(array $params = [])
    {
        if (! $this->manager) {
            $serializer = app()->make('InetStudio\AdminPanel\Base\Contracts\Serializers\SimpleDataArraySerializerContract');

            $includes = Arr::get($params, 'includes', []);

            $this->manager = new Manager();
            $this->manager->setSerializer($serializer);
            $this->manager->parseIncludes($includes);
        }

        return $this->manager;
    }

    /**
     * Возвращаем ключи группы.
     *
     * @param  string  $groupKey
     *
     * @return array
     */
    public function getGroupCacheKeys(string $groupKey = ''): array
    {
        $keys = [];

        if ($this->cacheStore instanceof RedisStore) {
            $prefix = $this->cacheStore->getPrefix();

            $tagKey = 'tag:'.$groupKey.':key';
            $setKey = Cache::get($tagKey);
            $setMembersKeys = $this->cacheStore->connection()->smembers($prefix.$setKey.':forever_ref');

            foreach ($setMembersKeys as $setMemberKey) {
                $setMemberKey = str_replace($prefix, '', $setMemberKey);

                $keys[] = Cache::get($setMemberKey);
            }
        } elseif ($this->cacheStore instanceof FileStore) {
            $keys = Cache::get($groupKey, []);
        }

        return $keys;
    }
}
