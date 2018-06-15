<?php

namespace Clerk\Clerk\Model\Adapter;

use Clerk\Clerk\Model\Config;
use Magento\Catalog\Model\Product as BaseProduct;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;

class Product extends AbstractAdapter
{
    /**
     * @var CollectionFactory
     */
    protected $collectionFactory;

    /**
     * @var string
     */
    protected $eventPrefix = 'product';

    /**
     * @var array
     */
    protected $fieldMap = [
        'entity_id' => 'id',
    ];

    /**
     * Product constructor.
     *
     * @param ScopeConfigInterface $scopeConfig
     * @param ManagerInterface $eventManager
     * @param CollectionFactory $collectionFactory
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        ManagerInterface $eventManager,
        CollectionFactory $collectionFactory,
        StoreManagerInterface $storeManager
    )
    {
        parent::__construct($scopeConfig, $eventManager, $storeManager, $collectionFactory);
    }

    /**
     * Prepare collection
     *
     * @return mixed
     */
    protected function prepareCollection($page, $limit, $orderBy, $order)
    {
        $collection = $this->collectionFactory->create();

        $collection->addFieldToSelect('*');

        //Filter on is_saleable if defined
        if ($this->scopeConfig->getValue(Config::XML_PATH_PRODUCT_SYNCHRONIZATION_SALABLE_ONLY)) {
            $collection->addFieldToFilter('is_saleable', true);
        }

        $visibility = $this->scopeConfig->getValue(Config::XML_PATH_PRODUCT_SYNCHRONIZATION_VISIBILITY);

        switch ($visibility) {
            case Visibility::VISIBILITY_IN_CATALOG:
                $collection->setVisibility([Visibility::VISIBILITY_IN_CATALOG]);
                break;
            case Visibility::VISIBILITY_IN_SEARCH:
                $collection->setVisibility([Visibility::VISIBILITY_IN_SEARCH]);
                break;
            case Visibility::VISIBILITY_BOTH:
                $collection->setVisibility([Visibility::VISIBILITY_BOTH]);
                break;
        }

        $collection->setPageSize($limit)
            ->setCurPage($page)
            ->addOrder($orderBy, $order);

        $this->eventManager->dispatch('clerk_' . $this->eventPrefix . '_get_collection_after', [
            'adapter' => $this,
            'collection' => $collection
        ]);

        return $collection;
    }

    /**
     * Get attribute value for product
     *
     * @param $resourceItem
     * @param $field
     * @return mixed
     */
    protected function getAttributeValue($resourceItem, $field)
    {
        $attribute = $resourceItem->getResource()->getAttribute($field);

        if ($attribute->usesSource()) {
            return $attribute->getSource()->getOptionText($resourceItem[$field]);
        }

        return parent::getAttributeValue($resourceItem, $field);
    }

    /**
     * Add field handlers for products
     */
    protected function addFieldHandlers()
    {
        //Add price fieldhandler
        $this->addFieldHandler('price', function($item) {
            try {
                $price = $item->getFinalPrice();
                return (float) $price;
            } catch(\Exception $e) {
                return 0;
            }
        });

        //Add list_price fieldhandler
        $this->addFieldHandler('list_price', function($item) {
            try {
                $price = $item->getPrice();

                //Fix for configurable products
                if ($item->getTypeId() === Configurable::TYPE_CODE) {
                    $price = $item->getPriceInfo()->getPrice('regular_price')->getValue();
                }

                return (float) $price;
            } catch(\Exception $e) {
                return 0;
            }
        });

        //Add image fieldhandler
        $this->addFieldHandler('image', function($item) {
            $store = $this->storeManager->getStore();
            $itemImage = $item->getImage() ?? $item->getSmallImage() ?? $item->getThumbnail();
            $imageUrl = $store->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_MEDIA) . 'catalog/product' . $itemImage;

            return $imageUrl;
        });

        //Add url fieldhandler
        $this->addFieldHandler('url', function($item) {
            return $item->getUrlModel()->getUrl($item);
        });

        //Add categories fieldhandler
        $this->addFieldHandler('categories', function($item) {
            return $item->getCategoryIds();
        });

        //Add age fieldhandler
        $this->addFieldHandler('age', function($item) {
            $createdAt = strtotime($item->getCreatedAt());
            $now = time();
            $diff = $now - $createdAt;
            return floor($diff/(60*60*24));
        });

        //Add on_sale fieldhandler
        $this->addFieldHandler('on_sale', function($item) {
            try {
                $finalPrice = $item->getFinalPrice();
                $price = $item->getPrice();

                return $finalPrice < $price;
            } catch (\Exception $e) {
                return false;
            }
        });
    }

    /**
     * Get default product fields
     *
     * @return array
     */
    protected function getDefaultFields()
    {
        $fields = [
            'name',
            'description',
            'price',
            'list_price',
            'image',
            'url',
            'categories',
            'brand',
            'sku',
            'age',
            'on_sale'
        ];

        $additionalFields = $this->scopeConfig->getValue(Config::XML_PATH_PRODUCT_SYNCHRONIZATION_ADDITIONAL_FIELDS);

        if ($additionalFields) {
            $fields = array_merge($fields, explode(',', $additionalFields));
        }

        return $fields;
    }
}