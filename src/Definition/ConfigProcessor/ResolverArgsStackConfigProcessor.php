<?php

declare(strict_types=1);

namespace Overblog\GraphQLBundle\Definition\ConfigProcessor;

use ArrayObject;
use Closure;
use GraphQL\Type\Definition\ResolveInfo;
use Overblog\GraphQLBundle\Definition\ArgumentFactory;
use Overblog\GraphQLBundle\Definition\LazyConfig;
use Overblog\GraphQLBundle\Resolver\ResolverArgs;
use Overblog\GraphQLBundle\Resolver\ResolverArgsStack;
use function is_array;
use function is_callable;

final class ResolverArgsStackConfigProcessor implements ConfigProcessorInterface
{
    private ResolverArgsStack $resolverArgsStack;

    private ArgumentFactory $argumentFactory;

    public function __construct(ResolverArgsStack $resolverArgsStack, ArgumentFactory $argumentFactory)
    {
        $this->resolverArgsStack = $resolverArgsStack;
        $this->argumentFactory = $argumentFactory;
    }

    public function process(LazyConfig $lazyConfig): LazyConfig
    {
        $lazyConfig->addPostLoader(function ($config) {
            if (isset($config['resolveField']) && is_callable($config['resolveField'])) {
                $config['resolveField'] = $this->wrapResolver($config['resolveField']);
            }

            if (isset($config['fields'])) {
                $config['fields'] = function () use ($config) {
                    $fields = $config['fields'];
                    if (is_callable($config['fields'])) {
                        $fields = $config['fields']();
                    }

                    return $this->wrapFieldsResolver($fields);
                };
            }

            return $config;
        });

        return $lazyConfig;
    }

    private function wrapFieldsResolver(array $fields): array
    {
        foreach ($fields as &$field) {
            if (is_array($field) && isset($field['resolve']) && is_callable($field['resolve'])) {
                $field['resolve'] = $this->wrapResolver($field['resolve']);
            }
        }

        return $fields;
    }

    private function wrapResolver(callable $resolver): Closure
    {
        $resolverArgsStack = $this->resolverArgsStack;

        return $this->argumentFactory->wrapResolverArgs(static function ($value, $args, ArrayObject $context, ResolveInfo $info) use ($resolver, $resolverArgsStack) {
            $resolverArgsStack->setCurrentResolverArgs(new ResolverArgs($value, $args, $context, $info));

            return $resolver(...func_get_args());
        });
    }
}
