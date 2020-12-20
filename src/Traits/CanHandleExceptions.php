<?php

namespace PB\ProductAccessByGroup\Traits;

use Exception;
use PrestaShop\PrestaShop\Adapter\SymfonyContainer;
use PB\ProductAccessByGroup\Exception\ExceptionHandler;
use PB\ProductAccessByGroup\Exception\PBException;
use PrestaShop\PrestaShop\Core\Module\Exception\ModuleErrorException;

trait CanHandleExceptions
{
    /**
     * Retrieves an ExceptionHandler instance.
     * This method can be overridden in order to set a custom
     * logger name, when using the trait outside of a Module
     * context.
     *
     * @return ExceptionHandler
     */
    protected function getExceptionHandler(): ExceptionHandler
    {
        // If no Symfony Container instance is available,
        // this means that we are in Legacy context.
        // Both BO modules and console commands can
        // freely access the container; only FO
        // components are unaware of it.
        $containerInstance = SymfonyContainer::getInstance();

        if ($containerInstance) {
            $exceptionHandler = $containerInstance->get('pb.exceptionhandler');
        } else {
            $exceptionHandler = (new ExceptionHandler())->setModuleInstance($this);
        }

        $exceptionHandler
            ->setLoggerName($this->name ?? $this->getName());

        return $exceptionHandler;
    }

    /**
     * Handles exceptions and displays message in more user friendly form.
     *
     * @param Exception $exception
     * @throws ModuleErrorException
     */
    protected function handleException(Exception $exception): void
    {
        if (!($exception instanceof PBException)) {
            $exception = new PBException($exception);
        }

        $this->getExceptionHandler()->handle($exception);
    }
}
