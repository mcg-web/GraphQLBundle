<?php

declare(strict_types=1);

namespace Overblog\GraphQLBundle\Resolver;

use ArrayObject;
use Closure;
use GraphQL\Type\Definition\ResolveInfo;
use Overblog\GraphQLBundle\Definition\ArgumentInterface;
use Overblog\GraphQLBundle\Definition\GlobalVariables;
use Overblog\GraphQLBundle\ExpressionLanguage\ExpressionLanguage;
use Overblog\GraphQLBundle\Generator\Converter\ExpressionConverter;
use Overblog\GraphQLBundle\Validator\InputValidator;
use function is_string;

final class ResolverFactory
{
    private const GRAPHQL_RESOLVER_ARGS = ['$value', '$args', '$context', '$info', '$validator', '$errors'];

    private int $countGraphQLResolverArgs;

    public function __construct()
    {
        $this->countGraphQLResolverArgs = count(self::GRAPHQL_RESOLVER_ARGS);
    }

    public function createExpressionResolver(string $expression, ExpressionConverter $expressionConverter, GlobalVariables $globalVariables, ?InputValidator $validator = null, ?array $validationGroups = null): Closure
    {
        $requiredInputValidator = ExpressionLanguage::expressionContainsVar('validator', $expression);
        $requiredInputValidatorErrors = ExpressionLanguage::expressionContainsVar('errors', $expression);

        /** @phpstan-ignore-next-line */
        return static function ($value, ArgumentInterface $args, ArrayObject $context, ResolveInfo $info) use ($expressionConverter, $expression, $globalVariables, $validator, $requiredInputValidator, $requiredInputValidatorErrors, $validationGroups) {
            $errors = null;
            if (null !== $validator) {
                $validator->setResolverArgs(new ResolverArgs(...func_get_args()));
                if ($requiredInputValidatorErrors) {
                    $errors = $validator->createResolveErrors($validationGroups);
                } elseif (!$requiredInputValidator) {
                    $validator->validate($validationGroups);
                }
            }

            static $code = null;
            if (null === $code) {
                $code = sprintf('return (static function() use ($value, $args, $context, $info, $validator, $errors, $globalVariables) { return %s; })();', $expressionConverter->convert($expression));
            }

            return eval($code);
        };
    }

    public function createResolver(callable $handler, array $resolverArgs, ?InputValidator $validator = null, ?array $validationGroups = null): Closure
    {
        $requiredInputValidator = in_array('$validator', $resolverArgs);
        $requiredInputValidatorErrors = in_array('$errors', $resolverArgs);

        $resolverArgs = array_values($resolverArgs);
        $useDefaultArguments = $this->canUseDefaultArguments($resolverArgs);

        return static function ($value, ArgumentInterface $args, ArrayObject $context, ResolveInfo $info) use ($handler, $resolverArgs, $validator, $requiredInputValidator, $requiredInputValidatorErrors, $validationGroups, $useDefaultArguments) {
            $errors = null;
            if (null !== $validator) {
                $validator->setResolverArgs(new ResolverArgs(...func_get_args()));

                if ($requiredInputValidatorErrors) {
                    $errors = $validator->createResolveErrors($validationGroups);
                } elseif (!$requiredInputValidator) {
                    $validator->validate($validationGroups);
                }
            }
            if ($useDefaultArguments) {
                $resolvedResolverArgs = [...func_get_args(), $validator, $errors];
            } else {
                $resolvedResolverArgs = $resolverArgs;

                static $needResolveArgs = null;
                if (null === $needResolveArgs) {
                    $needResolveArgs = [];
                    foreach ($resolverArgs as $index => $argumentValue) {
                        if (is_string($argumentValue) && '$' === $argumentValue[0]) {
                            $needResolveArgs[$index] = ltrim($argumentValue, '$');
                        }
                    }
                }

                foreach ($needResolveArgs as $index => $argumentValue) {
                    $resolvedResolverArgs[$index] = ${$needResolveArgs[$index]};
                }
            }

            return $handler(...$resolvedResolverArgs);
        };
    }

    private function canUseDefaultArguments(array $resolverArgs): bool
    {
        if ([] === $resolverArgs) {
            return true;
        }
        $numArgs = count($resolverArgs);
        if ($numArgs > $this->countGraphQLResolverArgs) {
            return false;
        }
        $graphqlResolverArgs = $numArgs === $this->countGraphQLResolverArgs ? self::GRAPHQL_RESOLVER_ARGS : array_slice(self::GRAPHQL_RESOLVER_ARGS, 0, $numArgs);

        return array_values($resolverArgs) === $graphqlResolverArgs;
    }
}
