<?php

class Category extends CategoryCore
{
    public function getProducts(
        $idLang,
        $p,
        $n,
        $orderyBy = null,
        $orderWay = null,
        $getTotal = false,
        $active = true,
        $random = false,
        $randomNumberProducts = 1,
        $checkAccess = true,
        Context $context = null
    ) {
        if (!$context) {
            $context = Context::getContext();
        }

        if ($checkAccess && !$this->checkAccess($context->customer->id)) {
            return false;
        }

        $front = in_array($context->controller->controller_type, array('front', 'modulefront'));
        $idSupplier = (int) Tools::getValue('id_supplier');

        $groups = FrontController::getCurrentCustomerGroups();

        /* Return only the number of products */
        if ($getTotal) {
            // productaccessbygroup
            if(Group::isFeatureActive() && Module::isEnabled('productaccessbygroup')) {
                $sql = 'SELECT COUNT(cp.`id_product`) AS total
                    FROM `' . _DB_PREFIX_ . 'product` p
                    ' . Shop::addSqlAssociation('product', 'p') . '
                    LEFT JOIN `' . _DB_PREFIX_ . 'category_product` cp ON p.`id_product` = cp.`id_product`
                    LEFT JOIN `' . _DB_PREFIX_ . 'product_group` pg
                            ON pg.`id_product` = p.`id_product` AND pg.`id_shop` = product_shop.`id_shop`
                    WHERE cp.`id_category` = ' . (int)$this->id .
                    ' AND pg.id_group ' . (count($groups) ? 'IN (' . implode(',', $groups) . ')' : '= 1') .
                    ($front ? ' AND product_shop.`visibility` IN ("both", "catalog")' : '') .
                    ($active ? ' AND product_shop.`active` = 1' : '') .
                    ($idSupplier ? 'AND p.id_supplier = ' . (int)$idSupplier : '');
            } else {
                $sql = 'SELECT COUNT(cp.`id_product`) AS total
					FROM `' . _DB_PREFIX_ . 'product` p
					' . Shop::addSqlAssociation('product', 'p') . '
					LEFT JOIN `' . _DB_PREFIX_ . 'category_product` cp ON p.`id_product` = cp.`id_product`
					WHERE cp.`id_category` = ' . (int)$this->id .
                    ($front ? ' AND product_shop.`visibility` IN ("both", "catalog")' : '') .
                    ($active ? ' AND product_shop.`active` = 1' : '') .
                    ($idSupplier ? ' AND p.id_supplier = ' . (int)$idSupplier : '');
            }

            return (int) Db::getInstance(_PS_USE_SQL_SLAVE_)->getValue($sql);
        }

        if ($p < 1) {
            $p = 1;
        }

        /** Tools::strtolower is a fix for all modules which are now using lowercase values for 'orderBy' parameter */
        $orderyBy = Validate::isOrderBy($orderyBy) ? Tools::strtolower($orderyBy) : 'position';
        $orderWay = Validate::isOrderWay($orderWay) ? Tools::strtoupper($orderWay) : 'ASC';

        $orderByPrefix = false;
        if ($orderyBy == 'id_product' || $orderyBy == 'date_add' || $orderyBy == 'date_upd') {
            $orderByPrefix = 'p';
        } elseif ($orderyBy == 'name') {
            $orderByPrefix = 'pl';
        } elseif ($orderyBy == 'manufacturer' || $orderyBy == 'manufacturer_name') {
            $orderByPrefix = 'm';
            $orderyBy = 'name';
        } elseif ($orderyBy == 'position') {
            $orderByPrefix = 'cp';
        }

        if ($orderyBy == 'price') {
            $orderyBy = 'orderprice';
        }

        $nbDaysNewProduct = Configuration::get('PS_NB_DAYS_NEW_PRODUCT');
        if (!Validate::isUnsignedInt($nbDaysNewProduct)) {
            $nbDaysNewProduct = 20;
        }

        // productaccessbygroup
        $accessjoin = '';
        $accesswhere = '';
        if(Group::isFeatureActive() && Module::isEnabled('productaccessbygroup'))
        {
            $accessjoin = ' LEFT JOIN `'._DB_PREFIX_.'product_group` pg
                    ON pg.`id_product` = p.`id_product` AND pg.`id_shop` = product_shop.`id_shop`';
            $accesswhere = ' AND pg.id_group '.(count($groups) ? 'IN ('.implode(',', $groups).')' : '= 1');
        }

        $sql = 'SELECT p.*, product_shop.*, stock.out_of_stock, IFNULL(stock.quantity, 0) AS quantity' . (Combination::isFeatureActive() ? ', IFNULL(product_attribute_shop.id_product_attribute, 0) AS id_product_attribute,
					product_attribute_shop.minimal_quantity AS product_attribute_minimal_quantity' : '') . ', pl.`description`, pl.`description_short`, pl.`available_now`,
					pl.`available_later`, pl.`link_rewrite`, pl.`meta_description`, pl.`meta_keywords`, pl.`meta_title`, pl.`name`, image_shop.`id_image` id_image,
					il.`legend` as legend, m.`name` AS manufacturer_name, cl.`name` AS category_default,
					DATEDIFF(product_shop.`date_add`, DATE_SUB("' . date('Y-m-d') . ' 00:00:00",
					INTERVAL ' . (int) $nbDaysNewProduct . ' DAY)) > 0 AS new, product_shop.price AS orderprice
				FROM `' . _DB_PREFIX_ . 'category_product` cp
				LEFT JOIN `' . _DB_PREFIX_ . 'product` p
					ON p.`id_product` = cp.`id_product`
				' . Shop::addSqlAssociation('product', 'p') .
            (Combination::isFeatureActive() ? ' LEFT JOIN `' . _DB_PREFIX_ . 'product_attribute_shop` product_attribute_shop
				ON (p.`id_product` = product_attribute_shop.`id_product` AND product_attribute_shop.`default_on` = 1 AND product_attribute_shop.id_shop=' . (int) $context->shop->id . ')' : '') . '
				' . Product::sqlStock('p', 0) . '
				LEFT JOIN `' . _DB_PREFIX_ . 'category_lang` cl
					ON (product_shop.`id_category_default` = cl.`id_category`
					AND cl.`id_lang` = ' . (int) $idLang . Shop::addSqlRestrictionOnLang('cl') . ')
				LEFT JOIN `' . _DB_PREFIX_ . 'product_lang` pl
					ON (p.`id_product` = pl.`id_product`
					AND pl.`id_lang` = ' . (int) $idLang . Shop::addSqlRestrictionOnLang('pl') . ')
				LEFT JOIN `' . _DB_PREFIX_ . 'image_shop` image_shop
					ON (image_shop.`id_product` = p.`id_product` AND image_shop.cover=1 AND image_shop.id_shop=' . (int) $context->shop->id . ')
				LEFT JOIN `' . _DB_PREFIX_ . 'image_lang` il
					ON (image_shop.`id_image` = il.`id_image`
					AND il.`id_lang` = ' . (int) $idLang . ')
				LEFT JOIN `' . _DB_PREFIX_ . 'manufacturer` m
					ON m.`id_manufacturer` = p.`id_manufacturer`
                '.$accessjoin.'
				WHERE product_shop.`id_shop` = ' . (int) $context->shop->id . '
					AND cp.`id_category` = ' . (int) $this->id
            .$accesswhere
            . ($active ? ' AND product_shop.`active` = 1' : '')
            . ($front ? ' AND product_shop.`visibility` IN ("both", "catalog")' : '')
            . ($idSupplier ? ' AND p.id_supplier = ' . (int) $idSupplier : '');

        if ($random === true) {
            $sql .= ' ORDER BY RAND() LIMIT ' . (int) $randomNumberProducts;
        } else {
            $sql .= ' ORDER BY ' . (!empty($orderByPrefix) ? $orderByPrefix . '.' : '') . '`' . bqSQL($orderyBy) . '` ' . pSQL($orderWay) . '
			LIMIT ' . (((int) $p - 1) * (int) $n) . ',' . (int) $n;
        }

        $result = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($sql, true, false);

        if (!$result) {
            return array();
        }

        if ($orderyBy == 'orderprice') {
            Tools::orderbyPrice($result, $orderWay);
        }

        // Modify SQL result
        return Product::getProductsProperties($idLang, $result);
    }
}