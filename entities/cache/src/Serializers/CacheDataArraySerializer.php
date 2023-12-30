<?php

namespace InetStudio\CachePackage\Cache\Serializers;

use Illuminate\Support\Facades\Cache;
use League\Fractal\Serializer\DataArraySerializer;
use InetStudio\CachePackage\Cache\Contracts\Serializers\CacheDataArraySerializerContract;

/**
 * Class CacheDataArraySerializer.
 */
class CacheDataArraySerializer extends DataArraySerializer implements CacheDataArraySerializerContract
{
    /**
     * Serialize a collection.
     *
     * @param string $resourceKey
     * @param array $data
     *
     * @return array
     */
    public function collection(?string $resourceKey, array $data): array
    {
        if ($resourceKey == 'cache') {
            $preparedData = [];

            foreach ($data as $item) {
                $preparedData[] = $this->prepareData($item);
            }

            $data = [
                'items_data' => $preparedData,
                'cached_data' => '',
            ];
        }

        return $data;
    }

    /**
     * Serialize an item.
     *
     * @param string $resourceKey
     * @param array $data
     *
     * @return array
     */
    public function item(?string $resourceKey, array $data): array
    {
        if ($resourceKey == 'cache') {
            $data = $this->prepareData($data);
        }

        return $data;
    }

    /**
     * Serialize null resource.
     *
     * @return array
     */
    public function null(): ?array
    {
        return [];
    }

    /**
     * Prepare transform data.
     *
     * @param  array  $data
     *
     * @return array
     */
    protected function prepareData(array $data): array
    {
        $alreadyCached = [];
        $preparedData = [];

        foreach ($data as $key => $value) {
            if (is_array($value) && isset($value['cached_data'])) {
                $alreadyCached[$key] = $value;

                continue;
            }

            $preparedData[$key] = $value;
        }

        $cacheKey = 'cached_'.md5(json_encode($preparedData));

        Cache::forever($cacheKey, $preparedData);

        $preparedData = [
            'cached_data' => $cacheKey,
        ];

        return $preparedData + $alreadyCached;
    }
}
