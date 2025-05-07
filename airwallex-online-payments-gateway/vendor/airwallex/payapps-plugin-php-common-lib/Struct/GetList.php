<?php

namespace Airwallex\PayappsPlugin\CommonLibrary\Struct;

class GetList extends AbstractBase
{
    /**
     * @var bool
     */
    protected $hasMore;

    /**
     * @var array
     */
    protected $items;

    /**
     * @return bool
     */
    public function hasMore(): bool
    {
        return $this->hasMore;
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
        return $this->items;
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
