<?php

declare(strict_types=1);

namespace Overblog\GraphQLBundle\Resolver;

use InvalidArgumentException;
use Overblog\GraphQLBundle\ExpressionLanguage\ResolverExpression;
use RuntimeException;
use function get_class;
use function is_array;
use function is_object;

final class Resolver
{
    public const CATEGORY_RESOLVER = 'resolver';
    public const CATEGORY_RESOLVER_FIELD = 'resolver_field';
    public const CATEGORY_RESOLVER_ACCESS = 'resolver_access';

    /** @var callable */
    private $handler;

    /** @var array<string,mixed> */
    private array $handlerArgs;

    /** @var callable[] */
    private array $argumentResolvers = [];

    private array $invokerArgNames;

    private string $resolverArgsClass;

    private bool $isExpressionHandler;

    public function __construct(callable $handler, array $handlerArgs, string $category = self::CATEGORY_RESOLVER)
    {
        $this->handler = $handler;
        $this->handlerArgs = $handlerArgs;
        $this->initInvokerArgNamesForCategory($category);
        $this->isExpressionHandler = $handler instanceof ResolverExpression;
    }

    public function addArgumentResolver(callable $resolver): self
    {
        $this->argumentResolvers[] = $resolver;

        return $this;
    }

    public function getHandler(): callable
    {
        return $this->handler;
    }

    public function getHandlerArgs(): array
    {
        return $this->handlerArgs;
    }

    /**
     * @param mixed ...$invokerArgs
     *
     * @return mixed
     */
    public function __invoke(...$invokerArgs)
    {
        $resolvedArgs = $this->resolveHandlerArgs(...$invokerArgs);

        if ($this->isExpressionHandler) {
            return ($this->handler)($resolvedArgs);
        } else {
            return ($this->handler)(...array_values($resolvedArgs));
        }
    }

    /**
     * @param mixed ...$invokerArgs
     */
    private function resolveHandlerArgs(...$invokerArgs): array
    {
        $invokerNamedArgs = [];
        // named invoker args
        foreach ($invokerArgs as $i => $v) {
            $invokerNamedArgs[$this->invokerArgNames[$i]] = $v;
        }

        // resolve using argument resolvers
        $resolvedArgs = [];
        $args = new $this->resolverArgsClass(...$invokerArgs);
        foreach ($this->argumentResolvers as $argumentResolvers) {
            $resolvedArgs = array_merge($resolvedArgs, $argumentResolvers($args, $this));
        }
        $resolvedArgs['resolverArgs'] = $args;

        // resolve args
        $resolvedHandlerArgs = [];
        foreach ($this->handlerArgs as $argumentName => $argumentValue) {
            if (is_string($argumentValue) && '$' === $argumentValue[0]) {
                $resolvedArgumentName = ltrim($argumentValue, '$');
                if (array_key_exists($resolvedArgumentName, $invokerNamedArgs)) {
                    $resolvedHandlerArgs[$argumentName] = $invokerNamedArgs[$resolvedArgumentName];
                } elseif (array_key_exists($resolvedArgumentName, $resolvedArgs)) {
                    $resolvedHandlerArgs[$argumentName] = $resolvedArgs[$resolvedArgumentName];
                } else {
                    throw $this->resolverArgumentUnresolvable($resolvedArgumentName);
                }
            } else {
                $resolvedHandlerArgs[$argumentName] = $argumentValue;
            }
        }

        return $resolvedHandlerArgs;
    }

    private function resolverArgumentUnresolvable(string $argumentName): RuntimeException
    {
        $representative = $this->handler;
        if (is_array($representative)) {
            $representative = sprintf('%s::%s()', get_class($representative[0]), $representative[1]);
        } elseif (is_object($representative)) {
            $representative = get_class($representative);
        }

        // @phpstan-ignore-next-line
        return new RuntimeException(sprintf('Resolver "%s" requires that you provide a value for the "$%s" argument. Either the argument is nullable and no null value has been provided, no default value has been provided or because there is a non optional argument after this one.', $representative, $argumentName));
    }

    private function initInvokerArgNamesForCategory(string $category): void
    {
        switch ($category) {
            case self::CATEGORY_RESOLVER:
            case self::CATEGORY_RESOLVER_FIELD:
            case self::CATEGORY_RESOLVER_ACCESS:
                $this->invokerArgNames = ['value', 'args', 'context', 'info'];
                $this->resolverArgsClass = ResolverArgs::class;
                break;

            default:
                throw new InvalidArgumentException(sprintf(
                    'Unknown category "%s".',
                    $category
                ));
        }
    }
}
