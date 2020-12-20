<?php

declare(strict_types = 1);

namespace PB\ProductAccessByGroup\Traits;

use Cache;
use Db;
use Group;
use Hook;
use Module;
use Shop;
use Validate;
use PrestaShop\PrestaShop\Adapter\LegacyLogger;
use PrestaShop\PrestaShop\Adapter\Module\ModuleDataProvider;

trait HandlesOverridesLifecycle
{
    /**
     * Insert module into datable.
     */
    public function installWithOverridesManagement()
    {
        Hook::exec('actionModuleInstallBefore', array('object' => $this));
        // Check module name validation
        if (!Validate::isModuleName($this->name)) {
            $this->_errors[] = Context::getContext()->getTranslator()->trans('Unable to install the module (Module name is not valid).', array(), 'Admin.Modules.Notification');

            return false;
        }

        // Check PS version compliancy
        if (!$this->checkCompliancy()) {
            $this->_errors[] = Context::getContext()->getTranslator()->trans('The version of your module is not compliant with your PrestaShop version.', array(), 'Admin.Modules.Notification');

            return false;
        }

        // Check module dependencies
        if (count($this->dependencies) > 0) {
            foreach ($this->dependencies as $dependency) {
                if (!Db::getInstance()->getRow('SELECT `id_module` FROM `' . _DB_PREFIX_ . 'module` WHERE LOWER(`name`) = \'' . pSQL(Tools::strtolower($dependency)) . '\'')) {
                    $error = Context::getContext()->getTranslator()->trans('Before installing this module, you have to install this/these module(s) first:', array(), 'Admin.Modules.Notification') . '<br />';
                    foreach ($this->dependencies as $d) {
                        $error .= '- ' . $d . '<br />';
                    }
                    $this->_errors[] = $error;

                    return false;
                }
            }
        }

        // Check if module is installed
        $result = (new ModuleDataProvider(new LegacyLogger(), $this->getTranslator()))->isInstalled($this->name);
        if ($result) {
            $this->_errors[] = Context::getContext()->getTranslator()->trans('This module has already been installed.', array(), 'Admin.Modules.Notification');

            return false;
        }

        if (!$this->installControllers()) {
            $this->_errors[] = Context::getContext()->getTranslator()->trans('Could not install module controllers.', array(), 'Admin.Modules.Notification');
            $this->uninstallOverrides();

            return false;
        }

        // Install module and retrieve the installation id
        $result = Db::getInstance()->insert($this->table, array('name' => $this->name, 'active' => 1, 'version' => $this->version));
        if (!$result) {
            $this->_errors[] = Context::getContext()->getTranslator()->trans('Technical error: PrestaShop could not install this module.', array(), 'Admin.Modules.Notification');
            $this->uninstallTabs();
            $this->uninstallOverrides();

            return false;
        }
        $this->id = Db::getInstance()->Insert_ID();

        if ($this->getOverrides() != null) {
            // Install overrides
            try {
                $this->installOverrides();
            } catch (Exception $e) {
                $this->_errors[] = Context::getContext()->getTranslator()->trans('Unable to install override: %s', array($e->getMessage()), 'Admin.Modules.Notification');
                $this->uninstallOverrides();

                return false;
            }
        }

        Cache::clean('Module::isInstalled' . $this->name);

        // Enable the module for current shops in context
        $this->enable();

        // Permissions management
        foreach (array('CREATE', 'READ', 'UPDATE', 'DELETE') as $action) {
            $slug = 'ROLE_MOD_MODULE_' . strtoupper($this->name) . '_' . $action;

            Db::getInstance()->execute(
                'INSERT INTO `' . _DB_PREFIX_ . 'authorization_role` (`slug`) VALUES ("' . $slug . '")'
            );

            Db::getInstance()->execute('
                INSERT INTO `' . _DB_PREFIX_ . 'module_access` (`id_profile`, `id_authorization_role`) (
                    SELECT id_profile, "' . Db::getInstance()->Insert_ID() . '"
                    FROM ' . _DB_PREFIX_ . 'access a
                    LEFT JOIN `' . _DB_PREFIX_ . 'authorization_role` r
                    ON r.id_authorization_role = a.id_authorization_role
                    WHERE r.slug = "ROLE_MOD_TAB_ADMINMODULESSF_' . $action . '"
            )');
        }

        // Adding Restrictions for client groups
        Group::addRestrictionsForModule($this->id, Shop::getShops(true, null, true));
        Hook::exec('actionModuleInstallAfter', array('object' => $this));

        if (Module::$update_translations_after_install) {
            $this->updateModuleTranslations();
        }

        return true;
    }

    /**
     * Delete module from datable.
     *
     * @return bool result
     */
    public function uninstallWithOverridesManagement()
    {
        // Check module installation id validation
        if (!Validate::isUnsignedId($this->id)) {
            $this->_errors[] = Context::getContext()->getTranslator()->trans('The module is not installed.', array(), 'Admin.Modules.Notification');

            return false;
        }

        // Uninstall overrides
        if (!$this->uninstallOverrides()) {
            return false;
        }

        // Retrieve hooks used by the module
        $sql = 'SELECT DISTINCT(`id_hook`) FROM `' . _DB_PREFIX_ . 'hook_module` WHERE `id_module` = ' . (int) $this->id;
        $result = Db::getInstance()->executeS($sql);
        foreach ($result as $row) {
            $this->unregisterHook((int) $row['id_hook']);
            $this->unregisterExceptions((int) $row['id_hook']);
        }

        foreach ($this->controllers as $controller) {
            $page_name = 'module-' . $this->name . '-' . $controller;
            $meta = Db::getInstance()->getValue('SELECT id_meta FROM `' . _DB_PREFIX_ . 'meta` WHERE page="' . pSQL($page_name) . '"');
            if ((int) $meta > 0) {
                Db::getInstance()->execute('DELETE FROM `' . _DB_PREFIX_ . 'meta_lang` WHERE id_meta=' . (int) $meta);
                Db::getInstance()->execute('DELETE FROM `' . _DB_PREFIX_ . 'meta` WHERE id_meta=' . (int) $meta);
            }
        }

        if ($this->getOverrides() != null) {
            $result &= $this->uninstallOverrides();
        }

        // Disable the module for all shops
        $this->disable(true);

        // Delete permissions module access
        $roles = Db::getInstance()->executeS('SELECT `id_authorization_role` FROM `' . _DB_PREFIX_ . 'authorization_role` WHERE `slug` LIKE "ROLE_MOD_MODULE_' . strtoupper($this->name) . '_%"');

        if (!empty($roles)) {
            foreach ($roles as $role) {
                Db::getInstance()->execute(
                    'DELETE FROM `' . _DB_PREFIX_ . 'module_access` WHERE `id_authorization_role` = ' . $role['id_authorization_role']
                );
                Db::getInstance()->execute(
                    'DELETE FROM `' . _DB_PREFIX_ . 'authorization_role` WHERE `id_authorization_role` = ' . $role['id_authorization_role']
                );
            }
        }

        // Remove restrictions for client groups
        Group::truncateRestrictionsByModule($this->id);

        // Uninstall the module
        if (Db::getInstance()->execute('DELETE FROM `' . _DB_PREFIX_ . 'module` WHERE `id_module` = ' . (int) $this->id)) {
            Cache::clean('Module::isInstalled' . $this->name);
            Cache::clean('Module::getModuleIdByName_' . pSQL($this->name));

            return true;
        }

        return false;
    }

    /**
     * Activate current module.
     * FIX ignore exceptions installing overrides in a multishop context
     *
     * @param bool $force_all If true, enable module for all shop
     */
    public function enable($force_all = false)
    {
        // Retrieve all shops where the module is enabled
        $list = Shop::getContextListShopID();
        if (!$this->id || !is_array($list)) {
            return false;
        }
        $sql = 'SELECT `id_shop` FROM `' . _DB_PREFIX_ . 'module_shop`
                WHERE `id_module` = ' . (int) $this->id .
            ((!$force_all) ? ' AND `id_shop` IN(' . implode(', ', $list) . ')' : '');

        // Store the results in an array
        $items = array();
        if ($results = Db::getInstance($sql)->executeS($sql)) {
            foreach ($results as $row) {
                $items[] = $row['id_shop'];
            }
        }

        // Enable module in the shop where it is not enabled yet
        foreach ($list as $id) {
            if (!in_array($id, $items)) {
                Db::getInstance()->insert('module_shop', array(
                    'id_module' => $this->id,
                    'id_shop' => $id,
                ));
            }
        }

        return true;
    }

    /**
     * Desactivate current module.
     *
     * @param bool $force_all If true, disable module for all shop
     */
    public function disable($force_all = false)
    {
        // Disable module for all shops
        $sql = 'DELETE FROM `' . _DB_PREFIX_ . 'module_shop` WHERE `id_module` = ' . (int) $this->id . ' ' . ((!$force_all) ? ' AND `id_shop` IN(' . implode(', ', Shop::getContextListShopID()) . ')' : '');

        return Db::getInstance()->execute($sql);
    }
}
