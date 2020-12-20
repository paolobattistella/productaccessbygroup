<?php

namespace PB\ProductAccessByGroup\Exception;

use Context;
use Module;
use Monolog\Logger;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\RotatingFileHandler;
use PrestaShopException;
use PrestaShop\PrestaShop\Adapter\SymfonyContainer;
use PrestaShop\PrestaShop\Core\Module\Exception\ModuleErrorException;

class ExceptionHandler
{
    private $_loggerName = 'exception_handler_logger';

    /**
     * @var null|Module
     */
    private $_moduleInstance = null;

    private $_errorReporting = true;

    public function getLoggerName(): string
    {
        return $this->_loggerName;
    }

    public function setLoggerName(string $loggerName): self
    {
        $this->_loggerName = strtolower(
            preg_replace('/[^a-zA-Z0-9\-_]+/', '_', $loggerName)
        );

        return $this;
    }

    public function setModuleInstance(Module $module): self
    {
        $this->_moduleInstance = $module;

        return $this;
    }

    /**
     * Notes down and notifies the user about a previously thrown Exception
     *
     * @param PBException $exception
     * @param string|null $loggerName
     * @throws ModuleErrorException
     */
    public function handle(PBException $exception, ?string $loggerName = null): void
    {
        if ($loggerName && trim($loggerName) !== '') {
            $this->setLoggerName($loggerName);
        }

        if ($this->_errorReporting) {
            $this->reportException($exception);
        }
    }

    public function disableErrorReporting(): self
    {
        $this->_errorReporting = false;

        return $this;
    }

    public function enableErrorReporting(): self
    {
        $this->_errorReporting = true;

        return $this;
    }

    private function logExceptionToFile(PBException $exception): void
    {
        $loggerName = $this->getLoggerName();
        $logLocation = _PS_ROOT_DIR_ . "/var/logs/modules/{$loggerName}.log";

        $logHandler = (new RotatingFileHandler($logLocation))->setFormatter(new JsonFormatter());
        $logger = (new Logger("{$loggerName}_logger"))->pushHandler($logHandler);

        $logger->critical(
            get_class($exception) . ' exception occurred: ',
            $exception->getTrace()
        );
    }

    private function reportException(PBException $exception): void
    {
        $message = $exception->getMessage();

        if (!$exception->isMessageLocalized()) {

            // Only when the translator instance is reachable (either
            // from the SymfonyContainer or from a Module instance)
            // the message will be localized.
            $message = sprintf('An unexpected error occurred. [%s code %s]', get_class($exception), $exception->getCode());

            if (SymfonyContainer::getInstance()) {
                $message = SymfonyContainer::getInstance()
                    ->get('translator')
                    ->trans(
                        'An unexpected error occurred. [%type% code %code%]',
                        [
                            '%type%' => get_class($exception),
                            '%code%' => $exception->getCode(),
                        ],
                        'Admin.Notifications.Error'
                    );
            } elseif ($this->_moduleInstance) {
                $message = $this->_moduleInstance
                    ->getTranslator()
                    ->trans(
                        'An unexpected error occurred. [%type% code %code%]',
                        [
                            '%type%' => get_class($exception),
                            '%code%' => $exception->getCode(),
                        ],
                        'Admin.Notifications.Error'
                    );
            }
        }

        if (!empty(Context::getContext()->controller) && Context::getContext()->controller->controller_type === 'front') {
            throw new PrestaShopException($message);
        } else {
            throw new ModuleErrorException($message);
        }
    }
}
