<?php

declare(strict_types=1);

namespace Overblog\GraphQLBundle\DependencyInjection\Compiler;

use GraphQL\Type\Definition\ResolveInfo;
use Overblog\GraphQLBundle\Resolver\Resolver;
use ReflectionMethod;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadataFactory;
use function is_array;
use function is_string;

class ArgumentResolverValuePass implements CompilerPassInterface
{
    private const ALLOWED_ARGUMENTS = ['$value', '$args', '$context', '$info'];

    public function process(ContainerBuilder $container): void
    {
        $argumentMetadataFactory = new ArgumentMetadataFactory();
        $configs = $container->getParameter('overblog_graphql_types.config');
        foreach ($configs as &$config) {
            foreach ($config['config']['fields'] ?? [] as $name => $field) {
                if (isset($field['resolver']['method'])) {
                    $methodName = $field['resolver']['method'];
                    $bind = $field['resolver']['bind'] ?? [];
                    list($resolverClass, $resolverMethod) = explode('::', $methodName, 2) + [null, null];
                    $id = $this->registerAnonymousResolverService(
                        $container, $argumentMetadataFactory, $resolverClass, $resolverMethod, $bind
                    );
                    // TODO(mcg-web): use id directly in TypeBuilder
                    $config['config']['fields'][$name]['resolve'] = "@=res('$id', [value, args, context, info])";
                    $config['config']['fields'][$name]['resolver']['id'] = $id;
                }
            }
        }
        $container->setParameter('overblog_graphql_types.config', $configs);
    }

    private function generateAnonymousResolverId(string $resolverClass, ?string $resolverMethod, array $bind): string
    {
        return sprintf(
            'overblog_graphql.anonymous_resolver_%s',
            substr(sha1(serialize([$resolverClass, $resolverMethod, $bind])), 0, 12)
        );
    }

    private function registerAnonymousResolverService(
        ContainerBuilder $container,
        ArgumentMetadataFactory $argumentMetadataFactory,
        string $resolverClass,
        ?string $resolverMethod,
        array $bind
    ): string {
        $isStatic = false;
        $resolverRef = null;
        try {
            if (null === $resolverMethod) {
                $resolverMethod ??= '__invoke';
            }
            $resolverRef = $resolverClass;
            $resolverClass = $container->findDefinition($resolverClass)->getClass() ?? $resolverClass;
            $resolver = [$resolverClass, $resolverMethod];
        } catch (ServiceNotFoundException $e) {
            if (function_exists($resolverClass)) {
                $resolver = $resolverClass;
            } else {
                if ('__invoke' !== $resolverMethod && $isStatic = (new ReflectionMethod($resolverClass, $resolverMethod))->isStatic()) {
                    $resolver = [$resolverClass, $resolverMethod];
                } else {
                    $container->register($resolverClass, $resolverClass)
                        ->setAutowired(true);
                    $resolver = [$resolverClass, $resolverMethod];
                }
            }
        }
        $id = $this->generateAnonymousResolverId($resolverClass, $resolverMethod, $bind);
        if (!$container->hasDefinition($id)) {
            $container->register($id, Resolver::class)
                ->setArguments([
                    $this->resolverReference($resolver, $resolverRef, $isStatic),
                    $this->resolveServices($this->namedArgumentValues($container, $argumentMetadataFactory, $resolver, $bind)),
                ])
                ->addTag('overblog_graphql.resolver')
            ;
        }

        return $id;
    }

    /**
     * @param array|string $resolver
     *
     * @return Reference|string|array
     */
    private function resolverReference($resolver, ?string $resolverRef, bool $isStatic)
    {
        if ($isStatic && is_array($resolver)) { // static method
            return join('::', $resolver);
        } elseif (is_string($resolver)) { // function
            return $resolver;
        } else {
            $reference = new Reference($resolverRef ?? $resolver[0]);

            return '__invoke' === $resolver[1] ? $reference : [$reference, $resolver[1]];
        }
    }

    /**
     * @param array|string $resolver
     */
    private function namedArgumentValues(
        ContainerBuilder $container, ArgumentMetadataFactory $argumentMetadataFactory, $resolver, array $bind
    ): array {
        $default = $bind + [
            '$value' => '$value',
            '$args' => '$args',
            '$info' => '$info',
            '$context' => '$context',
            ResolveInfo::class => '$info',
        ];
        $argumentValues = [];
        $arguments = $argumentMetadataFactory->createArgumentMetadata($resolver);
        foreach ($arguments as $argument) {
            if (null !== $argument->getType() && isset($default[$argument->getType().' $'.$argument->getName()])) {
                $argumentValues[$argument->getName()] = $default[$argument->getType().' $'.$argument->getName()];
            } elseif (null !== $argument->getType() && isset($default[$argument->getType()])) {
                $argumentValues[$argument->getName()] = $default[$argument->getType()];
            } elseif (isset($default['$'.$argument->getName()])) { // default values
                $argumentValues[$argument->getName()] = $default['$'.$argument->getName()];
            } elseif (null !== $argument->getType() && $container->has($argument->getType())) { // service
                $argumentValues[$argument->getName()] = '@'.$argument->getType();
            } elseif ($argument->hasDefaultValue() || (null !== $argument->getType() && $argument->isNullable() && !$argument->isVariadic())) { // default value from signature
                $argumentValues[$argument->getName()] = $argument->hasDefaultValue() ? $argument->getDefaultValue() : null;
            } else {
                // TODO(mcg-web): add message
                throw new InvalidArgumentException(sprintf('bind "%s"', $argument->getName()));
            }
        }

        return $argumentValues;
    }

    /**
     * @see \Symfony\Component\DependencyInjection\Loader\YamlFileLoader::resolveServices
     *
     * @param mixed $value
     *
     * @return array|false|string|Reference
     */
    private function resolveServices($value, bool $isParameter = false)
    {
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $value[$k] = $this->resolveServices($v, $isParameter);
            }
        } elseif (is_string($value)) {
            if (0 === strpos($value, '$') && !in_array($value, self::ALLOWED_ARGUMENTS)) {
                throw new InvalidArgumentException(sprintf('Argument "%s" is not supported.', $value));
            } elseif (0 === strpos($value, '@=')) {
                throw new InvalidArgumentException(sprintf('Expression syntax "%s" are not supported for now.', substr($value, 2)));
            } elseif (0 === strpos($value, '@')) {
                if (0 === strpos($value, '@@')) {
                    $value = substr($value, 1);
                    $invalidBehavior = null;
                } elseif (0 === strpos($value, '@!')) {
                    $value = substr($value, 2);
                    $invalidBehavior = ContainerInterface::IGNORE_ON_UNINITIALIZED_REFERENCE;
                } elseif (0 === strpos($value, '@?')) {
                    $value = substr($value, 2);
                    $invalidBehavior = ContainerInterface::IGNORE_ON_INVALID_REFERENCE;
                } else {
                    $value = substr($value, 1);
                    $invalidBehavior = ContainerInterface::EXCEPTION_ON_INVALID_REFERENCE;
                }

                if (null !== $invalidBehavior) {
                    $value = new Reference($value, $invalidBehavior);
                }
            }
        }

        return $value;
    }
}
