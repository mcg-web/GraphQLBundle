<?php

declare(strict_types=1);

namespace Overblog\GraphQLBundle\ExpressionLanguage\ExpressionFunction\GraphQL;

use Overblog\GraphQLBundle\ExpressionLanguage\ExpressionFunction;
use Overblog\GraphQLBundle\Generator\TypeGenerator;

final class Resolver extends ExpressionFunction
{
    public function __construct($name = 'resolver')
    {
        parent::__construct(
            $name,
            fn (string $alias, string $args = '[]') => "$this->globalVars->get('resolverResolver')->resolve([$alias, $args])",
            static fn ($arguments, string $alias, $args = []) => $arguments[TypeGenerator::GLOBAL_VARS]->get('resolverResolver')->resolve([$alias, $args]),
        );
    }
}
