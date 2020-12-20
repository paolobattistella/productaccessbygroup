<?php

namespace PB\ProductAccessByGroup\Exception;

use Context;
use Exception;
use Throwable;
use PrestaShop\PrestaShop\Adapter\SymfonyContainer;

class PBException extends Exception
{
    /**
     * Main error code.
     *
     * @var int
     */
    protected $code = 9001;

    /**
     * Whether exception message is an already localized
     * string.
     *
     * This field must be checked before returning any raw exception
     * message to the user, as it may not have been translated yet.
     *
     * @var bool
     */
    protected $messageLocalized = false;

    public function __construct(Throwable $previous = null, $message = "", $code = 9001)
    {
        parent::__construct($message, $code, $previous);
    }

    public function isMessageLocalized(): bool
    {
        return $this->messageLocalized;
    }

    public function getTranslator()
    {
        if (SymfonyContainer::getInstance()) {
            return SymfonyContainer::getInstance()->get('translator');
        } else {
            return Context::getContext()->getTranslator();
        }
    }
}
