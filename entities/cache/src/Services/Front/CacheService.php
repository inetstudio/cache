<?php

namespace InetStudio\CachePackage\Cache\Services\Front;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use League\Fractal\Manager;
use Illuminate\Cache\FileStore;
use Illuminate\Cache\RedisStore;
use League\Fractal\Resource\Item;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Contracts\Container\BindingResolutionException;
use InetStudio\CachePackage\Cache\Contracts\Services\Front\CacheServiceContract;

/**
 * Trait CacheService.
 */
class CacheService implements CacheServiceContract
{
    /**
     * @var
     */
    protected $cacheStore;

    /**
     * @var Manager
     */
    protected $manager;

    /**
     * @var array
     */
    protected $transformers = [];

    /**
     * @var array
     */
    protected $cacheKeys = [];

    /**
     * CacheService constructor.
     *
     * @throws BindingResolutionException
     */
    public function __construct()
    {
        $this->cacheStore = Cache::getStore();

        $this->initManager();
    }

    /**
     * Инициализируем менеджера трансформации.
     *
     * @throws BindingResolutionException
     */
    protected function initManager()
    {
        $serializer = app()->make('InetStudio\CachePackage\Cache\Contracts\Serializers\CacheDataArraySerializerContract');

        $this->manager = new Manager();
        $this->manager->setSerializer($serializer);
    }

    /**
     * Инициализация сервиса.
     *
     * @param $transformers
     * @param $keys
     * @param  array  $params
     *
     * @return CacheService
     *
     * @throws BindingResolutionException
     */
    public function init($transformers, $keys, array $params = []): self
    {
        $this->setTransformers($transformers);
        $this->setCacheKeys($keys);
        $this->parseParams($params);

        return $this;
    }

    /**
     * Устанавливаем трансформеры для обработки.
     *
     * @param  mixed $transformer
     * @param  mixed $additionalDataForKeys
     *
     * @return CacheService
     *
     * @throws BindingResolutionException
     */
    public function setTransformers($transformer, $additionalDataForKeys = ''): self
    {
        $transformers = (! is_iterable($transformer)) ? collect([$transformer]) : $transformer;

        if (count($transformers) == 0) {
            return $this;
        }

        $this->transformers = [];

        foreach ($transformers ?? [] as $key => $transformer) {
            $transformer = (is_string($transformer)) ? app()->make($transformer) : $transformer;
            $key = is_string($key) ? $key : '*';

            $this->transformers[$key] = [
                'transformer' => $transformer,
                'key' => md5(get_class($transformer).json_encode($additionalDataForKeys)),
            ];
        }

        return $this;
    }

    /**
     * Возвращаем трансформер для объекта.
     *
     * @param $item
     *
     * @return array|null
     */
    protected function getTransformerForItem($item): ?array
    {
        if (isset($this->transformers['*'])) {
            return $this->transformers['*'];
        }

        $interfaces = class_implements($item);

        $interface = array_intersect(array_keys($this->transformers), $interfaces);
        $interface = (count($interface) > 0) ? array_shift($interface) : null;

        if ($interface) {
            return $this->transformers[$interface];
        }

        $class = get_class($item);

        if (isset($this->transformers[$class])) {
            return $this->transformers[$class];
        }

        return null;
    }

    /**
     * Устанавливаем дополнительные ключи кеширования.
     *
     * @param $keys
     *
     * @return CacheService
     */
    public function setCacheKeys($keys): self
    {
        $keys = (! is_iterable($keys)) ? collect([$keys])->toArray() : $keys;

        if (count($keys) == 0) {
            return $this;
        }

        $this->cacheKeys = $keys;

        return $this;
    }

    /**
     * Обрабатываем параметры выборки.
     *
     * @param  array  $params
     *
     * @return CacheService
     */
    public function parseParams(array $params = []): self
    {
        $includes = Arr::get($params, 'includes', []);
        $this->manager->parseIncludes($includes);

        $cacheKeys = Arr::get($params, 'cache.keys', []);
        $this->cacheKeys = array_unique(array_merge($this->cacheKeys, $cacheKeys));

        $params = Arr::only($params, ['columns', 'includes']);
        $params = Arr::sortRecursive($params);

        foreach ($this->transformers as $model => $transformerData) {
            $this->transformers[$model]['key'] = md5($transformerData['key'].json_encode($params));
        }

        return $this;
    }

    /**
     * Кэшируем результаты запросов.
     *
     * @param  mixed  $items
     * @param  bool  $returnKeys
     *
     * @return Collection
     */
    public function cacheItems($items, bool $returnKeys = false): Collection
    {
        if (! is_iterable($items)) {
            $items = collect([$items]);
        }

        $data = [];

        foreach ($items as $item) {
            if ($item) {
                $transformerData = $this->getTransformerForItem($item);

                if (! $transformerData) {
                    continue;
                }

                $objectKey = md5(get_class($item).$item->id);

                $objectDataKey = md5(json_encode($item->getAttributes()).json_encode($item->getRelations()));

                $cacheKey = 'transform_'.$transformerData['key'].'_'.$objectDataKey.'_'.$objectKey;

                $groupCacheKey = 'cacheKeys_'.$objectKey;

                $cacheKeys = array_merge([$cacheKey], $this->cacheKeys);

                $this->addKeysToCacheGroup($groupCacheKey, $cacheKeys);
                $transformerData['transformer']->addCacheKeys($cacheKeys);

                $cachedItem = Cache::rememberForever($cacheKey, function () use ($item, $transformerData) {
                    $resource = new Item($item, $transformerData['transformer']);

                    return $this->manager->createData($resource)->toArray();
                });

                $data[] = ($returnKeys) ? $cacheKey : $cachedItem;
            }
        }

        return collect($data);
    }

    /**
     * Получаем кэшированные данные по ключам.
     *
     * @param  mixed  $keys
     *
     * @return Collection
     */
    public function getCachedItems($keys): Collection
    {
        if (! is_iterable($keys)) {
            $keys = collect([$keys]);
        }

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

        $items = $items->map(function ($item) {
            return (is_array($item)) ? $this->prepareItem($item) : $item;
        });

        return $items;
    }

    /**
     * Prepare cached item.
     *
     * @param  array  $item
     *
     * @return array
     */
    protected function prepareItem(array $item)
    {
        $cachedKeys = $this->getAllCachedKeys($item);

        $cachedItems = [];

        if (! empty($cachedKeys)) {
            if ($this->cacheStore instanceof RedisStore) {
                $cachedItems = Cache::many($cachedKeys);
            } else {
                foreach ($cachedKeys as $key) {
                    $cachedValue = Cache::get($key, []);
                    $cachedItems[$key] = $cachedValue;
                }
            }
        }

        return $this->replaceCachedData($item, $cachedItems);
    }

    /**
     * Get all cache_data values.
     *
     * @param  array  $array
     *
     * @return array
     */
    protected function getAllCachedKeys(array $array)
    {
        $keys = [];

        foreach ($array as $key => $value) {
            if ($key === 'cached_data') {
                $keys[] = $value;
            }

            if (is_array($value)) {
                $keys = array_merge($keys, $this->getAllCachedKeys($value));
            }
        }

        return array_unique(array_filter($keys));
    }

    /**
     * Repalce cache_data keys with data.
     *
     * @param $item
     * @param $cachedItems
     *
     * @return array
     */
    protected function replaceCachedData($item, $cachedItems)
    {
        $result = [];

        foreach ($item as $field => $value) {
            if ($field == 'cached_data' && $value == '') {
                continue;
            }

            if (is_array($value)) {
                $replacedData = $this->replaceCachedData($value, $cachedItems);

                if ($field === 'items_data') {
                    $result = $result + $replacedData;
                } else {
                    $result[$field] = $replacedData;
                }
            } else {
                if (is_string($value) && isset($cachedItems[$value])) {
                    $result = $result + $cachedItems[$value];
                } else {
                    $result[$field] = $value;
                }
            }
        }

        return $result;
    }

    /**
     * Добавляем ключи в группу.
     *
     * @param  string  $groupKey
     * @param  mixed  $additionalCacheKeys
     *
     * @return CacheService
     */
    public function addKeysToCacheGroup(string $groupKey, $additionalCacheKeys = []): self
    {
        if (! is_iterable($additionalCacheKeys)) {
            $additionalCacheKeys = collect([$additionalCacheKeys])->toArray();
        }

        if (empty($additionalCacheKeys)) {
            return $this;
        }

        $keys = [];

        if ($this->cacheStore instanceof FileStore) {
            $keys = Cache::get($groupKey, []);
        }

        if (empty(array_diff($additionalCacheKeys, $keys))) {
            return $this;
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

        return $this;
    }

    /**
     * Очищаем кэш по ключам.
     *
     * @param $item
     *
     * @return CacheService
     */
    public function clearCacheKeys($item): self
    {
        if ($item) {
            $cacheKey = 'cacheKeys_'.md5(get_class($item).$item->id);

            $this->clearCacheGroup($cacheKey);
        }

        return $this;
    }

    /**
     * Очищаем кэш по группе ключей.
     *
     * @param  string  $groupKey
     *
     * @return CacheService
     */
    public function clearCacheGroup(string $groupKey): self
    {
        if ($this->cacheStore instanceof RedisStore) {
            $prefix = $this->cacheStore->getPrefix();

            $tagKey = 'tag:'.$groupKey.':key';
            $setKey = Cache::get($tagKey);
            $setMembersKeys = $this->cacheStore->connection()->smembers($prefix.$setKey.':forever_ref');

            foreach ($setMembersKeys as $setMemberKey) {
                $setMemberKey = str_replace($prefix, '', $setMemberKey);
                $cacheKey = Cache::get($setMemberKey);

                if (Str::start($cacheKey, 'group_')) {
                    $this->clearCacheGroup($cacheKey);
                }

                Cache::forget($cacheKey);
            }

            Cache::forget($setKey);
            Cache::forget($tagKey);
            Cache::tags([$groupKey])->flush();
        } elseif ($this->cacheStore instanceof FileStore) {
            $keys = Cache::get($groupKey, []);

            foreach ($keys as $key) {
                if (Str::start($key, 'group_')) {
                    $this->clearCacheGroup($key);
                }

                Cache::forget($key);
            }

            Cache::forget($groupKey);
        }

        return $this;
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

    /**
     * Генерируем ключ для кеширования.
     *
     * @param  bool  group
     * @param  mixed  $additionalData
     * @param  int  $level
     *
     * @return string
     */
    public static function generateCacheKey($group = false, $additionalData = '', int $level = 1): string
    {
        $caller = debug_backtrace();

        $last = $caller[$level];

        $class = str_replace('\\', '_', $last['class']);
        $method = $last['function'];
        $arguments = md5(json_encode($additionalData).(($group) ? '' : json_encode($last['args'])));

        return (($group) ? 'group_' : '').$class.'_'.$method.'_'.$arguments;
    }

    /**
     * Получаем ключ по параметрам.
     *
     * @param $class
     * @param  string  $method
     * @param  array  $arguments
     * @param  bool  $group
     * @param  mixed  $additionalData
     *
     * @return string
     */
    public static function getCacheKeyByClassAndMethod($class, string $method, array $arguments = [], bool $group = false, $additionalData = ''): string
    {
        $class = str_replace('\\', '_', get_class($class));
        $arguments = md5(json_encode($additionalData).(($group) ? '' : json_encode($arguments)));

        return (($group) ? 'group_' : '').$class.'_'.$method.'_'.$arguments;
    }
}
