<?php

declare(strict_types=1);

namespace Overblog\GraphQLBundle\Resolver;

use ArrayObject;
use Closure;
use GraphQL\Type\Definition\ResolveInfo;
use Overblog\GraphQLBundle\Definition\ArgumentInterface;
use function is_string;

final class Resolver
{
    /**
     * @var callable
     */
    private $resolver;

    public function __construct(callable $resolver, array $resolverArgs)
    {
        $this->resolver = $this->createResolverCallable($resolver, $resolverArgs);
    }

    /**
     * @param mixed                  $value
     * @param ArrayObject|array|null $context
     *
     * @return mixed
     */
    public function resolve($value, ArgumentInterface $args, $context, ResolveInfo $info)
    {
        return ($this->resolver)(...func_get_args());
    }

    /**
     * @param mixed                  $value
     * @param ArrayObject|array|null $context
     *
     * @return mixed
     */
    public function __invoke($value, ArgumentInterface $args, $context, ResolveInfo $info)
    {
        return $this->resolve(...func_get_args());
    }

    private function createResolverCallable(callable $resolver, array $resolverArgs): Closure
    {
        $resolverArgs = array_values($resolverArgs);
        $needResolveArgs = [];

        foreach ($resolverArgs as $index => $argumentValue) {
            if (is_string($argumentValue) && '$' === $argumentValue[0]) {
                $needResolveArgs[$index] = ltrim($argumentValue, '$');
            }
        }

        return function ($value, ArgumentInterface $args, $context, ResolveInfo $info) use ($resolver, $resolverArgs, $needResolveArgs) {
            $resolvedResolverArgs = $resolverArgs;
            foreach ($needResolveArgs as $index => $argumentValue) {
                $resolvedResolverArgs[$index] = ${$needResolveArgs[$index]};
            }

            return $resolver(...$resolvedResolverArgs);
        };
    }
}
