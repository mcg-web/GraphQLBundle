<?php

declare(strict_types=1);

namespace Overblog\GraphQLBundle\ExpressionLanguage;

use Symfony\Component\ExpressionLanguage\ExpressionFunction;
use Symfony\Component\ExpressionLanguage\ExpressionFunctionProviderInterface;

final class ExpressionFunctionProvider implements ExpressionFunctionProviderInterface
{
    /**
     * @var ExpressionFunction[]
     */
    private iterable $functions;

    public function __construct(iterable $functions)
    {
        $this->functions = $functions;
    }

    /**
     * @return ExpressionFunction[]
     */
    public function getFunctions(): iterable
    {
        return $this->functions;
    }
}
