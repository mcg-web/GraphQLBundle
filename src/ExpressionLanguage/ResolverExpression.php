<?php

declare(strict_types=1);

namespace Overblog\GraphQLBundle\ExpressionLanguage;

use Murtukov\PHPCodeGenerator\Closure;
use Overblog\GraphQLBundle\Definition\GlobalVariables;
use Overblog\GraphQLBundle\Error\ResolveErrors;
use Overblog\GraphQLBundle\Generator\TypeGenerator;
use Overblog\GraphQLBundle\Resolver\ResolverArgs;
use Overblog\GraphQLBundle\Validator\InputValidator;
use Symfony\Component\ExpressionLanguage\Expression;

final class ResolverExpression
{
    private Expression $expression;

    private ?string $compiledCode;

    public function __construct(Expression $expression)
    {
        $this->expression = $expression;
        $this->compiledCode = null;
    }

    /**
     * @return mixed
     */
    public function __invoke(
        ResolverArgs $resolverArgs,
        GlobalVariables $globalVariables,
        ExpressionLanguage $expressionLanguage,
        ?InputValidator $validator,
        ?ResolveErrors $errors
    ) {
        if (null === $this->compiledCode) {
            $compiledCode = $expressionLanguage->compile(
                $this->expression,
               [
                   'value',
                    'args',
                    'context',
                    'info',
                    TypeGenerator::GLOBAL_VARS,
                    'validator',
                    'errors',
                ]
            );

            $closure = Closure::new()
                ->setStatic()
                ->addArguments()
                ->bindVars('resolverArgs', TypeGenerator::GLOBAL_VARS, 'validator', 'errors')
                ->append('\\extract($resolverArgs->toArray(true))')
                ->emptyLine()
                ->append('return ', $compiledCode)
            ;

            $this->compiledCode = sprintf('return (%s)();', $closure->generate());
        }

        return eval($this->compiledCode);
    }
}
