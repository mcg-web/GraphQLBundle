<?php

declare(strict_types=1);

namespace Overblog\GraphQLBundle\DependencyInjection\Compiler;

use GraphQL\Type\Definition\ResolveInfo;
use Overblog\GraphQLBundle\Definition\ArgumentInterface;
use Overblog\GraphQLBundle\Definition\GlobalVariables;
use Overblog\GraphQLBundle\Error\ResolveErrors;
use Overblog\GraphQLBundle\ExpressionLanguage\ResolverExpression;
use Overblog\GraphQLBundle\Generator\TypeGenerator;
use Overblog\GraphQLBundle\Resolver\Resolver;
use Overblog\GraphQLBundle\Resolver\ResolverArgs;
use Overblog\GraphQLBundle\Validator\InputValidator;
use ReflectionMethod;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadataFactory;
use function is_array;
use function is_string;

class ResolveNamedArgumentsPass implements CompilerPassInterface
{
    private array $expressionResolverDefinitions;

    public function process(ContainerBuilder $container): void
    {
        $argumentMetadataFactory = new ArgumentMetadataFactory();
        $configs = $container->getParameter('overblog_graphql_types.config');
        foreach ($configs as $typeName => &$config) {
            foreach ($config['config']['fields'] ?? [] as $fieldName => $field) {
                if (isset($field['resolver'])) {
                    if (!empty($field['resolver']['method'])) {
                        $methodName = $field['resolver']['method'];
                        list($resolverClass, $resolverMethod) = explode('::', $methodName, 2) + [null, null];
                        $resolverDefinition = $this->createAnonymousResolverDefinitionForMethod(
                            $container,
                            $argumentMetadataFactory,
                            $resolverClass,
                            $resolverMethod,
                            $field['resolver']['bind'] ?? [],
                            sprintf('%s.fields.%s.resolver', $typeName, $fieldName)
                        );
                    } else {
                        $expression = $field['resolver']['expression'];
                        $resolverDefinition = $this->createAnonymousResolverDefinitionForExpression($expression);
                    }
                    $container->setDefinition(
                        $id = sprintf('overblog_graphql._resolver_%s_%s', $config['config']['name'] ?? $typeName, $fieldName),
                        $resolverDefinition->setPublic(true)
                    );
                    $config['config']['fields'][$fieldName]['resolver']['id'] = $id;
                }
            }
        }
        $container->setParameter('overblog_graphql_types.config', $configs);
    }

    private function createAnonymousResolverDefinitionForExpression(string $expressionString): Definition
    {
        if (!isset($this->expressionResolverDefinitions[$expressionString])) {
            $this->expressionResolverDefinitions[$expressionString]
                = new Definition(ResolverExpression::class, [new Reference('overblog_graphql.expression_language'), $expressionString]);
        }

        return (new Definition(Resolver::class))
            ->setArguments([
                $this->expressionResolverDefinitions[$expressionString],
                [
                    'value' => '$value',
                    'args' => '$args',
                    'context' => '$context',
                    'info' => '$info',
                    TypeGenerator::GLOBAL_VARS => new Reference(GlobalVariables::class),
                ],
            ])
        ;
    }

    private function createAnonymousResolverDefinitionForMethod(
        ContainerBuilder $container,
        ArgumentMetadataFactory $argumentMetadataFactory,
        string $resolverClass,
        ?string $resolverMethod,
        array $bind,
        string $configPath
    ): Definition {
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

        return (new Definition(Resolver::class))
            ->setArguments([
                $this->resolverReference($resolver, $resolverRef, $isStatic),
                $this->resolveArgumentValues($container, $argumentMetadataFactory, $resolver, $bind, $configPath),
            ])
        ;
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
    private function resolveArgumentValues(
        ContainerBuilder $container, ArgumentMetadataFactory $argumentMetadataFactory, $resolver, array $bind, string $configPath
    ): array {
        $default = $bind + [
            '$value' => '$value',
            '$args' => '$args',
            '$info' => '$info',
            '$context' => '$context',
            '$validator' => '$validator',
            '$errors' => '$errors',
            '$resolverArgs' => '$resolverArgs',
            ResolverArgs::class => '$resolverArgs',
            ResolveErrors::class => '$errors',
            InputValidator::class => '$validator',
            ResolveInfo::class => '$info',
            ArgumentInterface::class => '$args',
        ];
        $argumentValues = [];
        $arguments = $argumentMetadataFactory->createArgumentMetadata($resolver);
        foreach ($arguments as $i => $argument) {
            if (isset($default[$i])) { // arg position
                $argumentValues[$argument->getName()] = $default[$i];
            } elseif (null !== $argument->getType() && isset($default[$argument->getType().' $'.$argument->getName()])) { // type and argument name
                $argumentValues[$argument->getName()] = $default[$argument->getType().' $'.$argument->getName()];
            } elseif (null !== $argument->getType() && isset($default[$argument->getType()])) { // typehint instance of
                $argumentValues[$argument->getName()] = $default[$argument->getType()];
            } elseif (isset($default['$'.$argument->getName()])) { // default values (graphql arguments)
                $argumentValues[$argument->getName()] = $default['$'.$argument->getName()];
            } elseif (null !== $argument->getType() && $container->has($argument->getType())) { // service
                $argumentValues[$argument->getName()] = new Reference($argument->getType());
            } elseif ($argument->hasDefaultValue() || (null !== $argument->getType() && $argument->isNullable() && !$argument->isVariadic())) { // default value from signature
                $argumentValues[$argument->getName()] = $argument->hasDefaultValue() ? $argument->getDefaultValue() : null;
            } else {
                $representative = $resolver;

                if (is_array($representative)) {
                    $representative = sprintf('%s::%s()', $representative[0], $representative[1]);
                } elseif (is_string($representative)) {
                    $representative = sprintf('%s()', $representative);
                }

                throw new InvalidArgumentException(sprintf('Resolver "%s" for path "%s" requires that you provide a value for the "$%s" argument. Either the argument is nullable and no null value has been provided, no default value has been provided or because there is a non optional argument after this one.', $representative, $configPath, $argument->getName()));
            }
        }

        return $argumentValues;
    }
}
