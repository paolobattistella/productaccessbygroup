<?php

declare(strict_types = 1);

if (!defined('_PS_VERSION_')) {
    exit;
}

use PB\ProductAccessByGroup\Hook\DisplayProductGroupHandler;
use PB\ProductAccessByGroup\Hook\ActionAfterCreateProductFormHandlerHandler;
use PB\ProductAccessByGroup\Hook\ActionAfterUpdateProductFormHandlerHandler;
use PB\ProductAccessByGroup\Traits\CanHandleExceptions;
use PB\ProductAccessByGroup\Traits\HandlesOverridesLifecycle;
use PB\ProductAccessByGroup\Setup\Installer;
use PrestaShop\PrestaShop\Adapter\SymfonyContainer;
use PrestaShop\PrestaShop\Core\Module\Exception\ModuleErrorException;

require_once __DIR__ . '/vendor/autoload.php';

class productaccessbygroup extends Module
{
    use CanHandleExceptions,
        HandlesOverridesLifecycle;

    public function __construct()
    {
        $this->name = 'productaccessbygroup';
        $this->version = '0.1.0';
        $this->author = 'Paolo Battistella';

        $this->tab = 'others';
        $this->need_instance = 0;

        parent::__construct();

        // Module labels and messages shown by the Admin panel
        $this->displayName = $this
            ->getTranslator()
            ->trans(
                'Module ProductAccessByGroup',
                [],
                'Modules.Productaccessbygroup.Productaccessbygroup'
            );
        $this->description = $this
            ->getTranslator()
            ->trans(
                'This module allows products to be restricted like categories, by customer group.',
                [],
                'Modules.Productaccessbygroup.Productaccessbygroup'
            );

        $this->ps_versions_compliancy = [
            'min' => '1.7.6',
            'max' => _PS_VERSION_,
        ];
    }

    /**
     * @inheritDoc
     * @throws ModuleErrorException
     */
    public function install(): bool
    {
        try {
            return $this->installWithOverridesManagement()
                && (new Installer($this))->install();

        } catch (Exception $exception) {
            $this->handleException($exception);
        }
    }

    /**
     * @inheritDoc
     * @throws ModuleErrorException
     */
    public function uninstall(): bool
    {
        try {

            return (new Installer($this))->uninstall()
                && $this->uninstallWithOverridesManagement();

        } catch (Exception $exception) {
            $this->handleException($exception);
        }
    }

    /**
     * Update product's forms, adding customers groups table
     *
     * @param array $hookParameters
     * @return string
     * @throws ModuleErrorException
     */
    public function hookDisplayAdminProductsOptionsStepTop(array $hookParameters): string
    {
        try {

            $productGroupTemplateData = (new DisplayProductGroupHandler($this))->handle($hookParameters);

            return SymfonyContainer::getInstance()->get('twig')
                ->render(
                    '@PrestaShop/Admin/Product/ProductPage/Includes/product_group.html.twig',
                    array_merge(
                        ['multi_shop_context' => Shop::getContext() !== Shop::CONTEXT_SHOP],
                        $productGroupTemplateData
                    )
                );

        } catch (Exception $exception) {
            $this->handleException($exception);
        }
    }

    /**
     * Handle the creation of a new product
     *
     * @param array $hookParameters
     * @throws ModuleErrorException
     */
    public function hookActionProductAdd(array $hookParameters): void
    {
        if (Shop::getContext() === Shop::CONTEXT_SHOP) {
            try {

                (new ActionAfterCreateProductFormHandlerHandler($this))->handle($hookParameters);

            } catch (Exception $exception) {
                $this->handleException($exception);
            }
        }
    }

    /**
     * Handle the update of an existing product, handling also the registration
     * of reference for contextual shop
     *
     * @param array $hookParameters
     * @throws ModuleErrorException
     */
    public function hookActionProductUpdate(array $hookParameters): void
    {
        if (Shop::getContext() === Shop::CONTEXT_SHOP) {
            try {

                (new ActionAfterUpdateProductFormHandlerHandler($this))->handle($hookParameters);

            } catch (Exception $exception) {
                $this->handleException($exception);
            }
        }
    }

    /**
     * Check if the module uses the new translation system.
     *
     * @return bool
     */
    public function isUsingNewTranslationSystem(): bool
    {
        return true;
    }
}
