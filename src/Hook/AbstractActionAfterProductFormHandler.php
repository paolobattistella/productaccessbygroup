<?php
declare(strict_types = 1);

namespace PB\ProductAccessByGroup\Hook;

use Context;
use Db;
use Exception;
use Module;
use Tools;
use PB\ProductAccessByGroup\Exception\CannotUpdateProduct;
use PrestaShop\PrestaShop\Core\CommandBus\CommandBusInterface;
use PrestaShop\PrestaShop\Adapter\SymfonyContainer;

abstract class AbstractActionAfterProductFormHandler
{
    /**
     * @var Module
     */
    private $_module;

    /**
     * @var CommandBusInterface
     */
    private $_queryBus;

    public function __construct(Module $module)
    {
        $this->_module = $module;
        $this->_queryBus = SymfonyContainer::getInstance()->get('prestashop.core.query_bus');
    }

    /**
     * Handles saving extra fields defined for Product entity
     *
     * @param array $hookParameters
     */
    public function handle(array $hookParameters): void
    {
        $productId = (int) $hookParameters['id_product'];

        $this->handleProductGroup($productId);
    }

    /**
     * Persists the ShopReference information for given product
     *
     * @param integer $productId
     */
    private function handleProductGroup(int $productId): void
    {
        try {
            if (Tools::getIsset('group_association')) {
                $shopId = Context::getContext()->shop->id;
                $groups = Tools::getValue('group_association');

                $query = 'DELETE FROM '._DB_PREFIX_ . 'product_group WHERE id_product = '.$productId.' AND id_shop = '.$shopId;
                Db::getInstance()->execute($query);

                foreach($groups as $groupId) {
                    if (!empty($groupId)) {
                        Db::getInstance()->insert(
                            'product_group',
                            [
                                'id_product' => $productId,
                                'id_shop' => $shopId,
                                'id_group' => $groupId,
                            ]
                        );
                    }
                }
            }

        } catch (Exception $exception) {
            throw new CannotUpdateProduct($exception);
        }
    }
}
