<?php

declare(strict_types=1);

namespace Overblog\GraphQLBundle\ExpressionLanguage;

final class ResolverExpression
{
    private ExpressionLanguage $expressionLanguage;

    private string $expression;

    public function __construct(ExpressionLanguage $expressionLanguage, string $expression)
    {
        $this->expressionLanguage = $expressionLanguage;
        $this->expression = $expression;
    }

    /**
     * @return mixed
     */
    public function __invoke(array $namedArguments)
    {
        return $this->expressionLanguage->evaluate(
            $this->expression,
            $namedArguments
        );
    }
}
