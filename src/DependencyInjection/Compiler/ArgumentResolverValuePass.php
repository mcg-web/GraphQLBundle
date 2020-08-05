<?php

declare(strict_types=1);

namespace Overblog\GraphQLBundle\DependencyInjection\Compiler;

use Closure;
use GraphQL\Type\Definition\ResolveInfo;
use Overblog\GraphQLBundle\Definition\ArgumentInterface;
use Overblog\GraphQLBundle\Definition\GlobalVariables;
use Overblog\GraphQLBundle\Error\ResolveErrors;
use Overblog\GraphQLBundle\Generator\Converter\ExpressionConverter;
use Overblog\GraphQLBundle\Generator\Exception\GeneratorException;
use Overblog\GraphQLBundle\Resolver\ResolverArgsStack;
use Overblog\GraphQLBundle\Resolver\ResolverFactory;
use Overblog\GraphQLBundle\Validator\InputValidator;
use Overblog\GraphQLBundle\Validator\ValidatorFactory;
use ReflectionMethod;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadataFactory;
use function is_array;
use function is_string;

class ArgumentResolverValuePass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $argumentMetadataFactory = new ArgumentMetadataFactory();
        $configs = $container->getParameter('overblog_graphql_types.config');
        foreach ($configs as &$config) {
            foreach ($config['config']['fields'] ?? [] as $name => $field) {
                if (isset($field['resolver'])) {
                    $mapping = $this->restructureObjectValidationConfig(
                        $config['config'],
                        $config['config']['fields'][$name]
                    );
                    $inputValidator = null;
                    $validationGroups = null;
                    if (null !== $mapping) {
                        $inputValidator = new Reference($this->registerInputValidatorService(
                            $container,
                            $mapping['properties'] ?? [],
                            $mapping['class'] ?? []
                        ));
                        $validationGroups = $mapping['validationGroups'] ?? null;
                    }

                    // TODO
                    /*if (null !== $validationConfig) {
                        $this->buildValidator($closure, $validationConfig, $injectValidator, $injectErrors);
                    } elseif (true === $injectValidator) {
                        throw new GeneratorException(
                            'Unable to inject an instance of the InputValidator. No validation constraints provided. '.
                            'Please remove the "validator" argument from the list of dependencies of your resolver '.
                            'or provide validation configs.'
                        );
                    }*/

                    if (!empty($field['resolver']['method'])) {
                        $methodName = $field['resolver']['method'];
                        list($resolverClass, $resolverMethod) = explode('::', $methodName, 2) + [null, null];
                        $id = $this->registerAnonymousResolverServiceForMethod(
                            $container, $inputValidator, $validationGroups, $argumentMetadataFactory, $resolverClass, $resolverMethod, $field['resolver']['bind'] ?? []
                        );
                    } else {
                        $expression = $field['resolver']['expression'];
                        $id = $this->registerAnonymousResolverServiceForExpression($container, $inputValidator, $validationGroups, $expression);
                    }
                    // TODO(mcg-web): use id directly in TypeBuilder
                    $config['config']['fields'][$name]['resolve'] = sprintf('@=res(\'%s\', [value, args, context, info])', $id);
                    $config['config']['fields'][$name]['resolver']['id'] = $id;
                }
            }
        }
        $container->setParameter('overblog_graphql_types.config', $configs);
    }

    private function generateShortHash(array $args): string
    {
        return substr(sha1(serialize($args)), 0, 12);
    }

    private function generateAnonymousResolverId(string $resolverClass, ?string $resolverMethod, array $bind): string
    {
        return sprintf(
            'overblog_graphql.anonymous_resolver_%s',
            $this->generateShortHash(func_get_args())
        );
    }

    private function generateAnonymousInputValidatorId(array $propertiesMapping, array $classMapping): string
    {
        return sprintf(
            'overblog_graphql.anonymous_input_validator_%s',
            $this->generateShortHash(func_get_args())
        );
    }

    private function registerAnonymousResolverServiceForExpression(
        ContainerBuilder $container,
        ?Reference $inputValidator,
        ?array $validationGroups,
        string $expressionString
    ): string {
        $id = $this->generateAnonymousResolverId($expressionString, null, []);
        if (!$container->hasDefinition($id)) {
            $container->register($id, Closure::class)
                ->setFactory([new Reference(ResolverFactory::class), 'createExpressionResolver'])
                ->setArguments([
                    $expressionString,
                    new Reference(ExpressionConverter::class),
                    new Reference(GlobalVariables::class),
                    $inputValidator,
                    $validationGroups,
                ])
                ->addTag('overblog_graphql.resolver')
            ;
        }

        return $id;
    }

    private function registerAnonymousResolverServiceForMethod(
        ContainerBuilder $container,
        ?Reference $inputValidator,
        ?array $validationGroups,
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
            $container->register($id, Closure::class)
                ->setFactory([new Reference(ResolverFactory::class), 'createResolver'])
                ->setArguments([
                    $this->resolverReference($resolver, $resolverRef, $isStatic),
                    $this->resolveArgumentValues($container, $argumentMetadataFactory, $resolver, $bind),
                    $inputValidator,
                    $validationGroups,
                ])
                ->addTag('overblog_graphql.resolver')
            ;
        }

        return $id;
    }

    private function registerInputValidatorService(
        ContainerBuilder $container,
        array $propertiesMapping,
        array $classMapping
    ): string {
        $id = $this->generateAnonymousInputValidatorId($propertiesMapping, $classMapping);
        if (!$container->hasDefinition($id)) {
            $container->register($id, InputValidator::class)
                ->setArguments([
                    new Reference(ResolverArgsStack::class),
                    new Reference('validator'),
                    new Reference(ValidatorFactory::class),
                    $propertiesMapping,
                    $classMapping,
                ])
                ->addTag('overblog_graphql.input_validator')
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
    private function resolveArgumentValues(
        ContainerBuilder $container, ArgumentMetadataFactory $argumentMetadataFactory, $resolver, array $bind
    ): array {
        $default = $bind + [
            '$value' => '$value',
            '$args' => '$args',
            '$info' => '$info',
            '$context' => '$context',
            '$validator' => '$validator',
            '$errors' => '$errors',
            ResolveErrors::class => '$errors',
            InputValidator::class => '$validator',
            ResolveInfo::class => '$info',
            ArgumentInterface::class => '$args',
        ];
        $argumentValues = [];
        $arguments = $argumentMetadataFactory->createArgumentMetadata($resolver);
        foreach ($arguments as $argument) {
            if (null !== $argument->getType() && isset($default[$argument->getType().' $'.$argument->getName()])) { // type and argument name
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
                // TODO(mcg-web): add message
                throw new InvalidArgumentException(sprintf('bind "%s"', $argument->getName()));
            }
        }

        return $argumentValues;
    }

    private function restructureObjectValidationConfig(array $config, array $fieldConfig): ?array
    {
        $properties = [];

        foreach ($fieldConfig['args'] ?? [] as $name => $arg) {
            if (empty($arg['validation'])) {
                continue;
            }

            $properties[$name] = $arg['validation'];

            if (empty($arg['validation']['cascade'])) {
                continue;
            }

            $properties[$name]['cascade']['isCollection'] = '[' === $arg['type'][0];
            $properties[$name]['cascade']['referenceType'] = trim($arg['type'], '[]!');
        }

        // Merge class and field constraints
        $classValidation = $config['validation'] ?? [];

        if (!empty($fieldConfig['validation'])) {
            $classValidation = array_replace_recursive($classValidation, $fieldConfig['validation']);
        }

        $mapping = [];

        if (!empty($properties)) {
            $mapping['properties'] = $properties;
        }

        // class
        if (!empty($classValidation)) {
            $mapping['class'] = $classValidation;
        }

        // validationGroups
        if (!empty($fieldConfig['validationGroups'])) {
            $mapping['validationGroups'] = $fieldConfig['validationGroups'];
        }

        if (empty($classValidation) && !array_filter($properties)) {
            return null;
        } else {
            return $mapping;
        }
    }
}
