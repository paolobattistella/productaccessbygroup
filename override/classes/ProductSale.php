<?php

class ProductSale extends ProductSaleCore
{
    public static function getNbSales()
    {
        // productaccessbygroup
        if(Group::isFeatureActive() && Module::isEnabled('productaccessbygroup'))
        {
            $groups = FrontController::getCurrentCustomerGroups();
            $sql = 'SELECT COUNT(ps.`id_product`) AS nb
                    FROM `'._DB_PREFIX_.'product_sale` ps
                    LEFT JOIN `'._DB_PREFIX_.'product` p ON p.`id_product` = ps.`id_product`
                    LEFT JOIN `'._DB_PREFIX_.'product_group` pg
                            ON pg.`id_product` = p.`id_product` AND pg.`id_shop` = product_shop.`id_shop`
                    '.Shop::addSqlAssociation('product', 'p', false).'
                    WHERE product_shop.`active` = 1
                    AND pg.id_group '.(count($groups) ? 'IN ('.implode(',', $groups).')' : '= 1').'';
        } else {
            $sql = 'SELECT COUNT(ps.`id_product`) AS nb
                    FROM `' . _DB_PREFIX_ . 'product_sale` ps
                    LEFT JOIN `' . _DB_PREFIX_ . 'product` p ON p.`id_product` = ps.`id_product`
                    ' . Shop::addSqlAssociation('product', 'p', false) . '
                    WHERE product_shop.`active` = 1';
        }
        return (int)Db::getInstance(_PS_USE_SQL_SLAVE_)->getValue($sql);
    }

    public static function getBestSales($id_lang, $page_number = 0, $nb_products = 10, $order_by = null, $order_way = null)
    {
        $context = Context::getContext();
        if ($page_number < 0) {
            $page_number = 0;
        }
        if ($nb_products < 1) {
            $nb_products = 10;
        }
        $final_order_by = $order_by;
        $order_table = '';

        if (is_null($order_by)) {
            $order_by = 'quantity';
            $order_table = 'ps';
        }

        if ($order_by == 'date_add' || $order_by == 'date_upd') {
            $order_table = 'product_shop';
        }

        if (is_null($order_way) || $order_by == 'sales') {
            $order_way = 'DESC';
        }

        $interval = Validate::isUnsignedInt(Configuration::get('PS_NB_DAYS_NEW_PRODUCT')) ? Configuration::get('PS_NB_DAYS_NEW_PRODUCT') : 20;

        // no group by needed : there's only one attribute with default_on=1 for a given id_product + shop
        // same for image with cover=1
        $sql = 'SELECT p.*, product_shop.*, stock.out_of_stock, IFNULL(stock.quantity, 0) as quantity,
                    '.(Combination::isFeatureActive()?'product_attribute_shop.minimal_quantity AS product_attribute_minimal_quantity,IFNULL(product_attribute_shop.id_product_attribute,0) id_product_attribute,':'').'
                    pl.`description`, pl.`description_short`, pl.`link_rewrite`, pl.`meta_description`,
                    pl.`meta_keywords`, pl.`meta_title`, pl.`name`, pl.`available_now`, pl.`available_later`,
                    m.`name` AS manufacturer_name, p.`id_manufacturer` as id_manufacturer,
                    image_shop.`id_image` id_image, il.`legend`,
                    ps.`quantity` AS sales, t.`rate`, pl.`meta_keywords`, pl.`meta_title`, pl.`meta_description`,
                    DATEDIFF(p.`date_add`, DATE_SUB("'.date('Y-m-d').' 00:00:00",
                    INTERVAL '.(int)$interval.' DAY)) > 0 AS new'
                .' FROM `'._DB_PREFIX_.'product_sale` ps
                LEFT JOIN `'._DB_PREFIX_.'product` p ON ps.`id_product` = p.`id_product`
                '.Shop::addSqlAssociation('product', 'p', false);

        if (Combination::isFeatureActive()) {
            $sql .= ' LEFT JOIN `'._DB_PREFIX_.'product_attribute_shop` product_attribute_shop
                            ON (p.`id_product` = product_attribute_shop.`id_product` AND product_attribute_shop.`default_on` = 1 AND product_attribute_shop.id_shop='.(int)$context->shop->id.')';
        }

        $sql .=    ' LEFT JOIN `'._DB_PREFIX_.'product_lang` pl
                    ON p.`id_product` = pl.`id_product`
                    AND pl.`id_lang` = '.(int)$id_lang.Shop::addSqlRestrictionOnLang('pl').'
                LEFT JOIN `'._DB_PREFIX_.'image_shop` image_shop
                    ON (image_shop.`id_product` = p.`id_product` AND image_shop.cover=1 AND image_shop.id_shop='.(int)$context->shop->id.')
                LEFT JOIN `'._DB_PREFIX_.'image_lang` il ON (image_shop.`id_image` = il.`id_image` AND il.`id_lang` = '.(int)$id_lang.')
                LEFT JOIN `'._DB_PREFIX_.'manufacturer` m ON (m.`id_manufacturer` = p.`id_manufacturer`)
                LEFT JOIN `'._DB_PREFIX_.'tax_rule` tr ON (product_shop.`id_tax_rules_group` = tr.`id_tax_rules_group`)
                    AND tr.`id_country` = '.(int)$context->country->id.'
                    AND tr.`id_state` = 0
                LEFT JOIN `'._DB_PREFIX_.'tax` t ON (t.`id_tax` = tr.`id_tax`)
                '.Product::sqlStock('p', 0);

        // productaccessbygroup
        if(Group::isFeatureActive() && Module::isEnabled('productaccessbygroup')) {
            $sql .= 'LEFT JOIN `' . _DB_PREFIX_ . 'product_group` pg
                        ON pg.`id_product` = p.`id_product` AND pg.`id_shop` = product_shop.`id_shop`';
        }

        $sql .= '
                WHERE product_shop.`active` = 1
                    AND p.`visibility` != \'none\'';

        if (Group::isFeatureActive()) {
            $groups = FrontController::getCurrentCustomerGroups();
            $sql .= ' AND EXISTS(SELECT 1 FROM `'._DB_PREFIX_.'category_product` cp
                    JOIN `'._DB_PREFIX_.'category_group` cg ON (cp.id_category = cg.id_category AND cg.`id_group` '.(count($groups) ? 'IN ('.implode(',', $groups).')' : '= 1').')
                    WHERE cp.`id_product` = p.`id_product`)';
        }

        // productaccessbygroup
        if(Group::isFeatureActive() && Module::isEnabled('productaccessbygroup')) {
            $sql .= ' AND pg.id_group ' . (count($groups) ? 'IN (' . implode(',', $groups) . ')' : '= 1');
        }

        if ($final_order_by != 'price') {
            $sql .= '
                    ORDER BY '.(!empty($order_table) ? '`'.pSQL($order_table).'`.' : '').'`'.pSQL($order_by).'` '.pSQL($order_way).'
                    LIMIT '.(int)($page_number * $nb_products).', '.(int)$nb_products;
        }

        $result = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($sql);

        if ($final_order_by == 'price') {
            Tools::orderbyPrice($result, $order_way);
        }
        if (!$result) {
            return false;
        }
        return Product::getProductsProperties($id_lang, $result);
    }

    public static function getBestSalesLight($id_lang, $page_number = 0, $nb_products = 10, Context $context = null)
    {
        if (!$context) {
            $context = Context::getContext();
        }
        if ($page_number < 0) {
            $page_number = 0;
        }
        if ($nb_products < 1) {
            $nb_products = 10;
        }

        // no group by needed : there's only one attribute with default_on=1 for a given id_product + shop
        // same for image with cover=1
        $sql = '
        SELECT
            p.id_product, IFNULL(product_attribute_shop.id_product_attribute,0) id_product_attribute, pl.`link_rewrite`, pl.`name`, pl.`description_short`, product_shop.`id_category_default`,
            image_shop.`id_image` id_image, il.`legend`,
            ps.`quantity` AS sales, p.`ean13`, p.`upc`, cl.`link_rewrite` AS category, p.show_price, p.available_for_order, IFNULL(stock.quantity, 0) as quantity, p.customizable,
            IFNULL(pa.minimal_quantity, p.minimal_quantity) as minimal_quantity, stock.out_of_stock,
            product_shop.`date_add` > "'.date('Y-m-d', strtotime('-'.(Configuration::get('PS_NB_DAYS_NEW_PRODUCT') ? (int)Configuration::get('PS_NB_DAYS_NEW_PRODUCT') : 20).' DAY')).'" as new,
            product_shop.`on_sale`, product_attribute_shop.minimal_quantity AS product_attribute_minimal_quantity
        FROM `'._DB_PREFIX_.'product_sale` ps
        LEFT JOIN `'._DB_PREFIX_.'product` p ON ps.`id_product` = p.`id_product`
        '.Shop::addSqlAssociation('product', 'p').'
        LEFT JOIN `'._DB_PREFIX_.'product_attribute_shop` product_attribute_shop
            ON (p.`id_product` = product_attribute_shop.`id_product` AND product_attribute_shop.`default_on` = 1 AND product_attribute_shop.id_shop='.(int)$context->shop->id.')
        LEFT JOIN `'._DB_PREFIX_.'product_attribute` pa ON (product_attribute_shop.id_product_attribute=pa.id_product_attribute)
        LEFT JOIN `'._DB_PREFIX_.'product_lang` pl
            ON p.`id_product` = pl.`id_product`
            AND pl.`id_lang` = '.(int)$id_lang.Shop::addSqlRestrictionOnLang('pl').'
        LEFT JOIN `'._DB_PREFIX_.'image_shop` image_shop
            ON (image_shop.`id_product` = p.`id_product` AND image_shop.cover=1 AND image_shop.id_shop='.(int)$context->shop->id.')
        LEFT JOIN `'._DB_PREFIX_.'image_lang` il ON (image_shop.`id_image` = il.`id_image` AND il.`id_lang` = '.(int)$id_lang.')
        LEFT JOIN `'._DB_PREFIX_.'category_lang` cl
            ON cl.`id_category` = product_shop.`id_category_default`
            AND cl.`id_lang` = '.(int)$id_lang.Shop::addSqlRestrictionOnLang('cl').Product::sqlStock('p', 0);

        // productaccessbygroup
        if(Group::isFeatureActive() && Module::isEnabled('productaccessbygroup')) {
            $groups = FrontController::getCurrentCustomerGroups();
            $sql .= 'LEFT JOIN `' . _DB_PREFIX_ . 'product_group` pg
                        ON pg.`id_product` = p.`id_product` AND pg.`id_shop` = product_shop.`id_shop`';
        }

        $sql .= '
        WHERE product_shop.`active` = 1
        AND p.`visibility` != \'none\'';

        if (Group::isFeatureActive()) {
            $groups = FrontController::getCurrentCustomerGroups();
            $sql .= ' AND EXISTS(SELECT 1 FROM `'._DB_PREFIX_.'category_product` cp
                JOIN `'._DB_PREFIX_.'category_group` cg ON (cp.id_category = cg.id_category AND cg.`id_group` '.(count($groups) ? 'IN ('.implode(',', $groups).')' : '= 1').')
                WHERE cp.`id_product` = p.`id_product`)';
        }

        // productaccessbygroup
        if(Group::isFeatureActive() && Module::isEnabled('productaccessbygroup')) {
            $sql .= ' AND pg.id_group ' . (count($groups) ? 'IN (' . implode(',', $groups) . ')' : '= 1');
        }
        $sql .= '
        ORDER BY ps.quantity DESC
        LIMIT '.(int)($page_number * $nb_products).', '.(int)$nb_products;

        if (!$result = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($sql)) {
            return false;
        }

        return Product::getProductsProperties($id_lang, $result);
    }    
}