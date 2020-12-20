<?php
declare(strict_types = 1);

namespace PB\ProductAccessByGroup\Setup;

use Db;
use PB\MtCommon\Setup\AbstractInstaller;
use PB\ProductAccessByGroup\Setup\Updates\UpdateTo010;
use PB\ProductAccessByGroup\Setup\Updates\UpdateTo020;
use PB\ProductAccessByGroup\Setup\Updates\UpdateTo030;
use PB\ProductAccessByGroup\Setup\Updates\UpdateTo031;

class Installer
{
    const PRODUCT_GROUP_TABLE = _DB_PREFIX_ . 'product_group';

    /**
     * @var Module
     */
    protected $module;

    protected $baseModuleHooks = [
        'actionProductUpdate',
        'actionProductAdd',
        'displayAdminProductsOptionsStepTop',
    ];

    protected $upgradeClasses = [];

    public function __construct(Module $module)
    {
        $this->module = $module;
    }

    /**
     * Method to perform the tasks required to register the
     * new module into the platform
     *
     * @return bool
     */
    public function install(): bool
    {
        return $this->installDatabase()
            && $this->registerHooks();
    }

    /**
     * Method to perform the tasks required to remove the
     * module from the platform
     *
     * @return bool
     */
    public function uninstall(): bool
    {
        return $this->uninstallDatabase();
    }

    public function installDatabase(): bool
    {
        return $this->createProductGroupTable();
    }

    public function uninstallDatabase(): bool
    {
        return $this->dropProductGroupTable();
    }

    private function registerHooks(): bool
    {
        foreach ($this->baseModuleHooks as $hookName) {
            if (!$this->module->registerHook($hookName)) {
                return false;
            }
        }

        foreach ($this->upgradeClasses as $upgrades) {
            /** @var Updater $updaterInstance */
            $updaterInstance = new $upgrades($this->module);

            if (!$updaterInstance->registerHooks()) {
                return false;
            }
        }

        return true;
    }

    private function createProductGroupTable(): bool
    {
        $tableName = self::PRODUCT_GROUP_TABLE;
        $databaseEngine = _MYSQL_ENGINE_;

        $tableCreationQuery = <<<SQL
            CREATE TABLE IF NOT EXISTS $tableName (
                `id_product` INT(10) UNSIGNED NOT NULL,
                `id_shop` INT(11) UNSIGNED NOT NULL,
                `id_group` INT(10) UNSIGNED NOT NULL,
                PRIMARY KEY (`id_product`,`id_shop`,`id_group`)
            ) ENGINE = $databaseEngine DEFAULT CHARSET = utf8
SQL;

        return Db::getInstance()->execute($tableCreationQuery);
    }

    private function dropProductGroupTable(): bool
    {
        $tableName = self::PRODUCT_GROUP_TABLE;
        $tableDeletionQuery = "DROP TABLE IF EXISTS `$tableName`";

        return Db::getInstance()->execute($tableDeletionQuery);
    }
}
