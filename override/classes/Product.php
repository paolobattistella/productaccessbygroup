<?php

class Product extends ProductCore
{
    public static function checkAccessStatic($id_product, $id_customer)
    {
        if (!Group::isFeatureActive()) {
            return true;
        }

        $cache_id = 'Product::checkAccess_'.(int)$id_product.'-'.(int)$id_customer.(!$id_customer ? '-'.(int)Group::getCurrent()->id : '');

        if (!Cache::isStored($cache_id)) {
            if (!$id_customer) {
                $result = (bool)Db::getInstance(_PS_USE_SQL_SLAVE_)->getValue('
				SELECT ctg.`id_group`
				FROM `'._DB_PREFIX_.'category_product` cp
				INNER JOIN `'._DB_PREFIX_.'category_group` ctg ON (ctg.`id_category` = cp.`id_category`)
				WHERE cp.`id_product` = '.(int)$id_product.' AND ctg.`id_group` = '.(int)Group::getCurrent()->id);
            } else {
                $result = (bool)Db::getInstance(_PS_USE_SQL_SLAVE_)->getValue('
				SELECT cg.`id_group`
				FROM `'._DB_PREFIX_.'category_product` cp
				INNER JOIN `'._DB_PREFIX_.'category_group` ctg ON (ctg.`id_category` = cp.`id_category`)
				INNER JOIN `'._DB_PREFIX_.'customer_group` cg ON (cg.`id_group` = ctg.`id_group`)
				WHERE cp.`id_product` = '.(int)$id_product.' AND cg.`id_customer` = '.(int)$id_customer);
            }

            if(Group::isFeatureActive() && Module::isEnabled('productaccessbygroup'))
            {
                $groups = Context::getContext()->customer->getGroups();
                $result = self::getProductCustomerGroupAccess($id_product, $groups);
            }

            Cache::store($cache_id, $result);
            return $result;
        }
        return Cache::retrieve($cache_id);
    }
    
    public static function getNewProducts($id_lang, $page_number = 0, $nb_products = 10, $count = false, $order_by = null, $order_way = null, Context $context = null)
    {
        if (!$context) {
            $context = Context::getContext();
        }

        $front = true;
        if (!in_array($context->controller->controller_type, array('front', 'modulefront'))) {
            $front = false;
        }

        if ($page_number < 0) {
            $page_number = 0;
        }
        if ($nb_products < 1) {
            $nb_products = 10;
        }
        if (empty($order_by) || $order_by == 'position') {
            $order_by = 'date_add';
        }
        if (empty($order_way)) {
            $order_way = 'DESC';
        }
        if ($order_by == 'id_product' || $order_by == 'price' || $order_by == 'date_add' || $order_by == 'date_upd') {
            $order_by_prefix = 'product_shop';
        } elseif ($order_by == 'name') {
            $order_by_prefix = 'pl';
        }
        if (!Validate::isOrderBy($order_by) || !Validate::isOrderWay($order_way)) {
            die(Tools::displayError());
        }

        $sql_groups = '';
        if (Group::isFeatureActive()) {
            $groups = FrontController::getCurrentCustomerGroups();
            $sql_groups = ' AND EXISTS(SELECT 1 FROM `'._DB_PREFIX_.'category_product` cp
				JOIN `'._DB_PREFIX_.'category_group` cg ON (cp.id_category = cg.id_category AND cg.`id_group` '.(count($groups) ? 'IN ('.implode(',', $groups).')' : '= 1').')
				WHERE cp.`id_product` = p.`id_product`)';
        }

        if (strpos($order_by, '.') > 0) {
            $order_by = explode('.', $order_by);
            $order_by_prefix = $order_by[0];
            $order_by = $order_by[1];
        }

        if ($count) {
            if(Module::isEnabled('productaccessbygroup')) {
                $sql = 'SELECT COUNT(p.`id_product`) AS nb
                        FROM `' . _DB_PREFIX_ . 'product` p
                        ' . Shop::addSqlAssociation('product', 'p') . '
                        LEFT JOIN `' . _DB_PREFIX_ . 'product_group` pg
                            ON pg.`id_product` = p.`id_product` AND pg.`id_shop` = product_shop.`id_shop`
                        WHERE product_shop.`active` = 1
                        AND pg.id_group ' . (count($groups) ? 'IN (' . implode(',', $groups) . ')' : '= 1') . '
                        AND product_shop.`date_add` > "' . date('Y-m-d', strtotime('-' . (Configuration::get('PS_NB_DAYS_NEW_PRODUCT') ? (int)Configuration::get('PS_NB_DAYS_NEW_PRODUCT') : 20) . ' DAY')) . '"
                        ' . ($front ? ' AND product_shop.`visibility` IN ("both", "catalog")' : '') . '
                        ' . $sql_groups;
            } else {
                $sql = 'SELECT COUNT(p.`id_product`) AS nb
    					FROM `' . _DB_PREFIX_ . 'product` p
    					' . Shop::addSqlAssociation('product', 'p') . '
    					WHERE product_shop.`active` = 1
    					AND product_shop.`date_add` > "' . date('Y-m-d', strtotime('-' . (Configuration::get('PS_NB_DAYS_NEW_PRODUCT') ? (int)Configuration::get('PS_NB_DAYS_NEW_PRODUCT') : 20) . ' DAY')) . '"
    					' . ($front ? ' AND product_shop.`visibility` IN ("both", "catalog")' : '') . '
    					' . $sql_groups;
            }
            return (int)Db::getInstance(_PS_USE_SQL_SLAVE_)->getValue($sql);
        }

        $sql = new DbQuery();
        $sql->select(
            'p.*, product_shop.*, stock.out_of_stock, IFNULL(stock.quantity, 0) as quantity, pl.`description`, pl.`description_short`, pl.`link_rewrite`, pl.`meta_description`,
			pl.`meta_keywords`, pl.`meta_title`, pl.`name`, pl.`available_now`, pl.`available_later`, image_shop.`id_image` id_image, il.`legend`, m.`name` AS manufacturer_name,
			product_shop.`date_add` > "'.date('Y-m-d', strtotime('-'.(Configuration::get('PS_NB_DAYS_NEW_PRODUCT') ? (int)Configuration::get('PS_NB_DAYS_NEW_PRODUCT') : 20).' DAY')).'" as new'
        );

        $sql->from('product', 'p');
        $sql->join(Shop::addSqlAssociation('product', 'p'));
        $sql->leftJoin('product_lang', 'pl', '
			p.`id_product` = pl.`id_product`
			AND pl.`id_lang` = '.(int)$id_lang.Shop::addSqlRestrictionOnLang('pl')
        );
        $sql->leftJoin('image_shop', 'image_shop', 'image_shop.`id_product` = p.`id_product` AND image_shop.cover=1 AND image_shop.id_shop='.(int)$context->shop->id);
        $sql->leftJoin('image_lang', 'il', 'image_shop.`id_image` = il.`id_image` AND il.`id_lang` = '.(int)$id_lang);
        $sql->leftJoin('manufacturer', 'm', 'm.`id_manufacturer` = p.`id_manufacturer`');

        $sql->where('product_shop.`active` = 1');
        if ($front) {
            $sql->where('product_shop.`visibility` IN ("both", "catalog")');
        }
        $sql->where('product_shop.`date_add` > "'.date('Y-m-d', strtotime('-'.(Configuration::get('PS_NB_DAYS_NEW_PRODUCT') ? (int)Configuration::get('PS_NB_DAYS_NEW_PRODUCT') : 20).' DAY')).'"');
        if (Group::isFeatureActive()) {
            $groups = FrontController::getCurrentCustomerGroups();
            $sql->where('EXISTS(SELECT 1 FROM `'._DB_PREFIX_.'category_product` cp
				JOIN `'._DB_PREFIX_.'category_group` cg ON (cp.id_category = cg.id_category AND cg.`id_group` '.(count($groups) ? 'IN ('.implode(',', $groups).')' : '= 1').')
				WHERE cp.`id_product` = p.`id_product`)');
        }

        $sql->orderBy((isset($order_by_prefix) ? pSQL($order_by_prefix).'.' : '').'`'.pSQL($order_by).'` '.pSQL($order_way));
        $sql->limit($nb_products, $page_number * $nb_products);

        if (Combination::isFeatureActive()) {
            $sql->select('product_attribute_shop.minimal_quantity AS product_attribute_minimal_quantity, IFNULL(product_attribute_shop.id_product_attribute,0) id_product_attribute');
            $sql->leftJoin('product_attribute_shop', 'product_attribute_shop', 'p.`id_product` = product_attribute_shop.`id_product` AND product_attribute_shop.`default_on` = 1 AND product_attribute_shop.id_shop='.(int)$context->shop->id);
        }
        $sql->join(Product::sqlStock('p', 0));


        // nemo

        if(Module::isEnabled('productaccessbygroup'))
        {
            $sql->leftJoin('product_group', 'pg', 'pg.`id_product` = p.`id_product` AND pg.`id_shop` = product_shop.`id_shop`');
            $sql->where('pg.id_group '.(count($groups) ? 'IN ('.implode(',', $groups).')' : '= 1'));
        }


        $result = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($sql);

        if (!$result) {
            return false;
        }

        if ($order_by == 'price') {
            Tools::orderbyPrice($result, $order_way);
        }

        $products_ids = array();
        foreach ($result as $row) {
            $products_ids[] = $row['id_product'];
        }
        // Thus you can avoid one query per product, because there will be only one query for all the products of the cart
        Product::cacheFrontFeatures($products_ids, $id_lang);
        return Product::getProductsProperties((int)$id_lang, $result);
    }
    
    public static function getPricesDrop($id_lang, $page_number = 0, $nb_products = 10, $count = false,
                                         $order_by = null, $order_way = null, $beginning = false, $ending = false, Context $context = null)
    {
        if (!Validate::isBool($count)) {
            die(Tools::displayError());
        }

        if (!$context) {
            $context = Context::getContext();
        }
        if ($page_number < 0) {
            $page_number = 0;
        }
        if ($nb_products < 1) {
            $nb_products = 10;
        }
        if (empty($order_by) || $order_by == 'position') {
            $order_by = 'price';
        }
        if (empty($order_way)) {
            $order_way = 'DESC';
        }
        if ($order_by == 'id_product' || $order_by == 'price' || $order_by == 'date_add' || $order_by == 'date_upd') {
            $order_by_prefix = 'product_shop';
        } elseif ($order_by == 'name') {
            $order_by_prefix = 'pl';
        }
        if (!Validate::isOrderBy($order_by) || !Validate::isOrderWay($order_way)) {
            die(Tools::displayError());
        }
        $current_date = date('Y-m-d H:i:00');
        $ids_product = Product::_getProductIdByDate((!$beginning ? $current_date : $beginning), (!$ending ? $current_date : $ending), $context);

        $tab_id_product = array();
        foreach ($ids_product as $product) {
            if (is_array($product)) {
                $tab_id_product[] = (int)$product['id_product'];
            } else {
                $tab_id_product[] = (int)$product;
            }
        }

        $front = true;
        if (!in_array($context->controller->controller_type, array('front', 'modulefront'))) {
            $front = false;
        }

        $sql_groups = '';
        if (Group::isFeatureActive()) {
            $groups = FrontController::getCurrentCustomerGroups();
            $sql_groups = ' AND EXISTS(SELECT 1 FROM `'._DB_PREFIX_.'category_product` cp
				JOIN `'._DB_PREFIX_.'category_group` cg ON (cp.id_category = cg.id_category AND cg.`id_group` '.(count($groups) ? 'IN ('.implode(',', $groups).')' : '= 1').')
				WHERE cp.`id_product` = p.`id_product`)';
        }

        if ($count) {
            // productaccessbygroup
            if(Group::isFeatureActive() && Module::isEnabled('productaccessbygroup')) {
                return Db::getInstance(_PS_USE_SQL_SLAVE_)->getValue('
                SELECT COUNT(DISTINCT p.`id_product`)
                FROM `' . _DB_PREFIX_ . 'product` p
                ' . Shop::addSqlAssociation('product', 'p') . '
                LEFT JOIN `' . _DB_PREFIX_ . 'product_group` pg
                            ON pg.`id_product` = p.`id_product` AND pg.`id_shop` = product_shop.`id_shop`
                WHERE product_shop.`active` = 1
                AND product_shop.`show_price` = 1
                AND pg.id_group ' . (count($groups) ? 'IN (' . implode(',', $groups) . ')' : '= 1') . '
                ' . ($front ? ' AND product_shop.`visibility` IN ("both", "catalog")' : '') . '
                ' . ((!$beginning && !$ending) ? 'AND p.`id_product` IN(' . ((is_array($tab_id_product) && count($tab_id_product)) ? implode(', ', $tab_id_product) : 0) . ')' : '') . '
                ' . $sql_groups);
            } else {
                return Db::getInstance(_PS_USE_SQL_SLAVE_)->getValue('
    			SELECT COUNT(DISTINCT p.`id_product`)
    			FROM `' . _DB_PREFIX_ . 'product` p
    			' . Shop::addSqlAssociation('product', 'p') . '
    			WHERE product_shop.`active` = 1
    			AND product_shop.`show_price` = 1
    			' . ($front ? ' AND product_shop.`visibility` IN ("both", "catalog")' : '') . '
    			' . ((!$beginning && !$ending) ? 'AND p.`id_product` IN(' . ((is_array($tab_id_product) && count($tab_id_product)) ? implode(', ', $tab_id_product) : 0) . ')' : '') . '
    			' . $sql_groups);
            }
        }

        if (strpos($order_by, '.') > 0) {
            $order_by = explode('.', $order_by);
            $order_by = pSQL($order_by[0]).'.`'.pSQL($order_by[1]).'`';
        }

        // productaccessbygroup
        $accessjoin = '';
        $accesswhere = '';
        if(Group::isFeatureActive() && Module::isEnabled('productaccessbygroup')) {
            $accessjoin = ' LEFT JOIN `'._DB_PREFIX_.'product_group` pg
                    ON pg.`id_product` = p.`id_product` AND pg.`id_shop` = product_shop.`id_shop`';
            $accesswhere = ' AND pg.id_group '.(count($groups) ? 'IN ('.implode(',', $groups).')' : '= 1');
        }

        $sql = '
		SELECT
			p.*, product_shop.*, stock.out_of_stock, IFNULL(stock.quantity, 0) as quantity, pl.`description`, pl.`description_short`, pl.`available_now`, pl.`available_later`,
			IFNULL(product_attribute_shop.id_product_attribute, 0) id_product_attribute,
			pl.`link_rewrite`, pl.`meta_description`, pl.`meta_keywords`, pl.`meta_title`,
			pl.`name`, image_shop.`id_image` id_image, il.`legend`, m.`name` AS manufacturer_name,
			DATEDIFF(
				p.`date_add`,
				DATE_SUB(
					"'.date('Y-m-d').' 00:00:00",
					INTERVAL '.(Validate::isUnsignedInt(Configuration::get('PS_NB_DAYS_NEW_PRODUCT')) ? Configuration::get('PS_NB_DAYS_NEW_PRODUCT') : 20).' DAY
				)
			) > 0 AS new
		FROM `'._DB_PREFIX_.'product` p
		'.Shop::addSqlAssociation('product', 'p').'
		LEFT JOIN `'._DB_PREFIX_.'product_attribute_shop` product_attribute_shop
			ON (p.`id_product` = product_attribute_shop.`id_product` AND product_attribute_shop.`default_on` = 1 AND product_attribute_shop.id_shop='.(int)$context->shop->id.')
		'.Product::sqlStock('p', 0, false, $context->shop).'
		LEFT JOIN `'._DB_PREFIX_.'product_lang` pl ON (
			p.`id_product` = pl.`id_product`
			AND pl.`id_lang` = '.(int)$id_lang.Shop::addSqlRestrictionOnLang('pl').'
		)
		LEFT JOIN `'._DB_PREFIX_.'image_shop` image_shop
			ON (image_shop.`id_product` = p.`id_product` AND image_shop.cover=1 AND image_shop.id_shop='.(int)$context->shop->id.')
		LEFT JOIN `'._DB_PREFIX_.'image_lang` il ON (image_shop.`id_image` = il.`id_image` AND il.`id_lang` = '.(int)$id_lang.')
		LEFT JOIN `'._DB_PREFIX_.'manufacturer` m ON (m.`id_manufacturer` = p.`id_manufacturer`)
        '.$accessjoin.'
		WHERE product_shop.`active` = 1
		AND product_shop.`show_price` = 1
        '.$accesswhere.'
		'.($front ? ' AND p.`visibility` IN ("both", "catalog")' : '').'
		'.((!$beginning && !$ending) ? ' AND p.`id_product` IN ('.((is_array($tab_id_product) && count($tab_id_product)) ? implode(', ', $tab_id_product) : 0).')' : '').'
		'.$sql_groups.'
		ORDER BY '.(isset($order_by_prefix) ? pSQL($order_by_prefix).'.' : '').pSQL($order_by).' '.pSQL($order_way).'
		LIMIT '.(int)($page_number * $nb_products).', '.(int)$nb_products;

        $result = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($sql);

        if (!$result) {
            return false;
        }

        if ($order_by == 'price') {
            Tools::orderbyPrice($result, $order_way);
        }

        return Product::getProductsProperties($id_lang, $result);
    }

    /**
     * Returns true if the current customer can access a product
     * @param  int $productId current product id
     * @param  array $groupIds list of customer groups the customer belongs to
     * @return bool             access granted or not
     */
    public function getProductCustomerGroupAccess($productId, $groupIds)
    {
        $shopId = Context::getContext()->shop->id;
        $query = 'SELECT id_group
			FROM '._DB_PREFIX_.'product_group
			WHERE id_product = '.(int)$productId.'
			AND id_shop = '.(int)$shopId.'
			AND id_group IN ('.implode(',', $groupIds).')';
        return (bool)Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($query);
    }

}