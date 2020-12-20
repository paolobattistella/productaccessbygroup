<?php

declare(strict_types = 1);

namespace PB\ProductAccessByGroup\Exception;

use Throwable;
use PrestaShop\PrestaShop\Adapter\SymfonyContainer;

class CannotUpdateProduct extends PBException
{
    public function __construct(Throwable $previous = null, $message = "", $code = 9001)
    {
        if (empty($message)) {
            $this->messageLocalized = true;

            $message = SymfonyContainer::getInstance()
                ->get('translator')
                ->trans(
                    'An error occurs updating product.', [],
                    'Modules.Productaccessbygroup.Exceptions'
                );
        }

        parent::__construct($previous, $message, $code);
    }
}
