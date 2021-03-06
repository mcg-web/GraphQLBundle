<?php

declare(strict_types=1);

namespace Overblog\GraphQLBundle\Error;

interface ExceptionConverterInterface
{
    /**
     * Tries to convert a raw exception into a user warning or error
     * that is displayed to the user.
     */
    public function convertException(\Throwable $exception): \Throwable;
}
