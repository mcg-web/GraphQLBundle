<?php

declare(strict_types=1);

namespace Overblog\GraphQLBundle\Resolver;

use Closure;
use GraphQL\Type\Definition\ResolveInfo;
use Overblog\GraphQLBundle\Definition\ArgumentFactory;
use Overblog\GraphQLBundle\Definition\ArgumentInterface;
use Overblog\GraphQLBundle\Definition\GlobalVariables;
use Overblog\GraphQLBundle\Generator\Converter\ExpressionConverter;
use function is_string;

final class ResolverFactory
{
    private const GRAPHQL_RESOLVER_ARGS = ['$value', '$args', '$context', '$info'];

    private int $countGraphQLResolverArgs;

    private ArgumentFactory $argumentFactory;

    public function __construct(ArgumentFactory $argumentFactory)
    {
        $this->countGraphQLResolverArgs = count(self::GRAPHQL_RESOLVER_ARGS);
        $this->argumentFactory = $argumentFactory;
    }

    public function createExpressionResolver(string $expression, ExpressionConverter $expressionConverter, GlobalVariables $globalVariables): Closure
    {
        $code = sprintf('return %s;', $expressionConverter->convert($expression));

        /** @phpstan-ignore-next-line */
        return $this->argumentFactory->wrapResolverArgs(static function ($value, ArgumentInterface $args, $context, ResolveInfo $info) use ($code, $globalVariables) {
            return eval($code);
        });
    }

    public function createResolver(callable $handler, array $resolverArgs): Closure
    {
        $resolverArgs = array_values($resolverArgs);
        $needResolveArgs = [];

        foreach ($resolverArgs as $index => $argumentValue) {
            if (is_string($argumentValue) && '$' === $argumentValue[0]) {
                $needResolveArgs[$index] = ltrim($argumentValue, '$');
            }
        }

        if ($this->canUseDefaultArguments($resolverArgs)) {
            return $this->argumentFactory->wrapResolverArgs($handler);
        } else {
            return $this->argumentFactory->wrapResolverArgs(static function ($value, ArgumentInterface $args, $context, ResolveInfo $info) use ($handler, $resolverArgs, $needResolveArgs) {
                $resolvedResolverArgs = $resolverArgs;
                foreach ($needResolveArgs as $index => $argumentValue) {
                    $resolvedResolverArgs[$index] = ${$needResolveArgs[$index]};
                }

                return $handler(...$resolvedResolverArgs);
            });
        }
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
