<?php

namespace Airwallex\PayappsPlugin\CommonLibrary\Struct;

class GetList extends AbstractBase
{
    /**
     * @var bool
     */
    private $hasMore;

    /**
     * @var array
     */
    private $items;

    /**
     * @return bool
     */
    public function hasMore(): bool
    {
        return $this->hasMore ?? false;
    }

    /**
     * @param bool $hasMore
     *
     * @return GetList
     */
    public function setHasMore(bool $hasMore): GetList
    {
        $this->hasMore = $hasMore;
        return $this;
    }

    /**
     * @return array
     */
    public function getItems(): array
    {
        return $this->items ?? [];
    }

    /**
     * @param array $items
     *
     * @return GetList
     */
    public function setItems(array $items): GetList
    {
        $this->items = $items;
        return $this;
    }
}
