<?php

declare(strict_types=1);

namespace Overblog\GraphQLBundle\Resolver;

use ArrayObject;
use Closure;
use GraphQL\Type\Definition\ResolveInfo;
use Overblog\GraphQLBundle\Definition\ArgumentInterface;
use Overblog\GraphQLBundle\Validator\InputValidator;
use function is_string;

final class ResolverFactory
{
    private const GRAPHQL_RESOLVER_ARGS = ['$value', '$args', '$context', '$info', '$validator', '$errors', '$resolverArgs'];

    private int $countGraphQLResolverArgs;

    public function __construct()
    {
        $this->countGraphQLResolverArgs = count(self::GRAPHQL_RESOLVER_ARGS);
    }

    public function createResolver(callable $handler, array $handlerArgs, ?InputValidator $validator = null, ?array $validationGroups = null): Closure
    {
        $requiredInputValidator = in_array('$validator', $handlerArgs);
        $requiredInputValidatorErrors = in_array('$errors', $handlerArgs);

        $handlerArgs = array_values($handlerArgs);
        $useDefaultArguments = $this->canUseDefaultArguments($handlerArgs);

        return static function ($value, ArgumentInterface $args, ArrayObject $context, ResolveInfo $info) use ($handler, $handlerArgs, $validator, $requiredInputValidator, $requiredInputValidatorErrors, $validationGroups, $useDefaultArguments) {
            $errors = null;
            $resolverArgs = new ResolverArgs(...func_get_args());
            if (null !== $validator) {
                $validator->setResolverArgs($resolverArgs);

                if ($requiredInputValidatorErrors) {
                    $errors = $validator->createResolveErrors($validationGroups);
                } elseif (!$requiredInputValidator) {
                    $validator->validate($validationGroups);
                }
            }
            if ($useDefaultArguments) {
                $resolvedResolverArgs = [...func_get_args(), $validator, $errors];
            } else {
                $resolvedResolverArgs = $handlerArgs;

                static $needResolveArgs = null;
                if (null === $needResolveArgs) {
                    $needResolveArgs = [];
                    foreach ($handlerArgs as $index => $argumentValue) {
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
