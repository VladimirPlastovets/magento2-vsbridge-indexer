<?php
/**
 * @package   Divante\VsbridgeIndexerCatalog
 * @author    Agata Firlejczyk <afirlejczyk@divante.pl>
 * @copyright 2019 Divante Sp. z o.o.
 * @license   See LICENSE_DIVANTE.txt for license details.
 */

namespace Divante\VsbridgeIndexerCatalog\Model\ResourceModel\Product;

use Divante\VsbridgeIndexerCatalog\Model\ProductMetaData;
use Magento\Catalog\Model\ResourceModel\Product\Gallery as GalleryResource;
use Magento\Eav\Model\Entity\Attribute as EntityAttribute;
use Magento\Framework\DB\Select;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\Store;

/**
 * Class Gallery
 */
class Gallery
{
    /**
     * @var ResourceConnection
     */
    private $resource;

    /**
     * @var EntityAttribute
     */
    private $entityAttribute;

    /**
     * @var ProductMetaData
     */
    private $metadataPool;

    /**
     * Gallery constructor.
     *
     * @param ProductMetaData $metadataPool
     * @param ResourceConnection $resourceModel
     * @param EntityAttribute $attribute
     */
    public function __construct(
        ProductMetaData $metadataPool,
        ResourceConnection $resourceModel,
        EntityAttribute $attribute
    ) {
        $this->metadataPool = $metadataPool;
        $this->entityAttribute = $attribute;
        $this->resource = $resourceModel;
    }

    /**
     * @param array $linkFieldIds
     * @param $storeId
     *
     * @return array
     * @throws \Exception
     */
    public function loadGallerySet(array $linkFieldIds, $storeId)
    {
        $select = $this->getLoadGallerySelect($linkFieldIds, $storeId);

        return $this->getConnection()->fetchAll($select);
    }

    /**
     * @return mixed
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    private function getMediaGalleryAttributeId()
    {
        $attribute = $this->entityAttribute->loadByCode(\Magento\Catalog\Model\Product::ENTITY, 'media_gallery');

        return $attribute->getId();
    }

    /**
     * @param array $linkFieldIds
     * @param int   $storeId
     *
     * @return Select
     * @throws \Exception
     */
    private function getLoadGallerySelect(array $linkFieldIds, $storeId)
    {
        $linkField = $this->metadataPool->get()->getLinkField();
        $attributeId = $this->getMediaGalleryAttributeId();
        $connection = $this->getConnection();

        $mainTableAlias = 'main';
        $positionCheckSql = $this->getConnection()->getCheckSql(
            'value.position IS NULL',
            'default_value.position',
            'value.position'
        );

        // Select gallery images for product
        $select = $connection->select()
            ->from(
                [$mainTableAlias => $this->resource->getTableName(GalleryResource::GALLERY_TABLE)],
                [
                    'value_id',
                    'file' => 'value'
                ]
            )->joinInner(
                ['entity' => $this->resource->getTableName(GalleryResource::GALLERY_VALUE_TO_ENTITY_TABLE)],
                $mainTableAlias . '.value_id = entity.value_id',
                []
            )
            ->joinLeft(
                ['value' => $this->resource->getTableName(GalleryResource::GALLERY_VALUE_TABLE)],
                implode(
                    ' AND ',
                    [
                        $mainTableAlias . '.value_id = value.value_id',
                        $this->getConnection()->quoteInto('value.store_id = ?', (int)$storeId),
                        'value.' . $linkField . ' = entity.' . $linkField,
                    ]
                ),
                []
            )
            ->joinLeft( // Joining default values
                ['default_value' => $this->resource->getTableName(GalleryResource::GALLERY_VALUE_TABLE)],
                implode(
                    ' AND ',
                    [
                        $mainTableAlias . '.value_id = default_value.value_id',
                        $this->getConnection()->quoteInto('default_value.store_id = ?', Store::DEFAULT_STORE_ID),
                        'default_value.' . $linkField . ' = entity.' . $linkField,
                    ]
                ),
                []
            )
            ->columns([
                'row_id' => 'entity.'.$linkField,
                'label' => $this->getConnection()->getIfNullSql('`value`.`label`', '`default_value`.`label`'),
                'position' => $this->getConnection()->getIfNullSql('`value`.`position`', '`default_value`.`position`'),
                'label_default' => 'default_value.label',
                'position_default' => 'default_value.position',
            ])
            ->where('main.attribute_id = ?', $attributeId)
            ->where('entity.' . $linkField . ' IN (?)', $linkFieldIds)
            ->where('default_value.disabled is NULL or default_value.disabled != 1')
            ->where('value.disabled is NULL or value.disabled != 1')
            ->order($positionCheckSql . ' ' . Select::SQL_ASC);

        return $select;
    }

    /**
     * @return \Magento\Framework\DB\Adapter\AdapterInterface
     */
    private function getConnection()
    {
        return $this->resource->getConnection();
    }
}
