<?php

declare(strict_types = 1);

namespace PB\ProductAccessByGroup\Hook;

use Context;
use Db;
use Exception;
use Group;
use Module;
use PB\ProductAccessByGroup\Exception\CannotDisplayProductForm;

class DisplayProductGroupHandler
{
    /**
     * @var Module
     */
    private $_module;

    public function __construct(Module $module)
    {
        $this->_module = $module;
    }

    /**
     * Handles the execution of the displayAdminProductsMainStepLeftColumnBottom hook
     *
     * @param array $hookParameters
     * @return array
     * @throws CannotDisplayProductForm
     */
    public function handle(array $hookParameters): array
    {
        try {
            $hookReturnData = [];

            $shopId = Context::getContext()->shop->id;
            $languageId = Context::getContext()->language->id;

            $groups = Group::getGroups($languageId, $shopId);
            $shopGroups = [];
            foreach($groups as $group) {
                $shopGroups[$group['name']] = [
                    'id_group' => $group['id_group'],
                    'name' => $group['name'],
                ];
            }
            $hookReturnData['shop_groups'] = $shopGroups;

            $groups = Db::getInstance()->executeS('SELECT id_group FROM '._DB_PREFIX_ . 'product_group WHERE id_product = '.$hookParameters['id_product'].' AND id_shop = '.$shopId);
            $productGroups = [];
            if (!empty($groups)) {
                foreach ($groups as $group) {
                    $productGroups[] = $group['id_group'];
                }
            }
            $hookReturnData['product_groups'] = $productGroups;

            return $hookReturnData;

        } catch (Exception $exception) {
            throw new CannotDisplayProductForm($exception);
        }
    }
}
