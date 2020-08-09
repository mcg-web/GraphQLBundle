<?php

declare(strict_types=1);

namespace Overblog\GraphQLBundle\DependencyInjection\Compiler;

use Closure;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;
use Overblog\GraphQLBundle\Definition\ArgumentInterface;
use Overblog\GraphQLBundle\Definition\GlobalVariables;
use Overblog\GraphQLBundle\Error\ResolveErrors;
use Overblog\GraphQLBundle\ExpressionLanguage\ExpressionLanguage;
use Overblog\GraphQLBundle\ExpressionLanguage\ResolverExpression;
use Overblog\GraphQLBundle\Resolver\ResolverArgs;
use Overblog\GraphQLBundle\Resolver\ResolverFactory;
use Overblog\GraphQLBundle\Resolver\TypeResolver;
use Overblog\GraphQLBundle\Validator\InputValidator;
use Overblog\GraphQLBundle\Validator\ValidatorFactory;
use ReflectionMethod;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadataFactory;
use function is_array;
use function is_string;

class ResolveNamedArgumentsPass implements CompilerPassInterface
{
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
                    $this->addValidatorRequirementsToResolverDefinition(
                        $container,
                        $resolverDefinition,
                        $config['config'],
                        $config['config']['fields'][$fieldName],
                    );
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

    private function addValidatorRequirementsToResolverDefinition(
        ContainerBuilder $container, Definition $resolverDefinition, array $typeConfig, array $fieldConfig
    ): void {
        $mapping = $this->restructureObjectValidationConfig($typeConfig, $fieldConfig);
        $inputValidatorDefinition = null;
        $validationGroups = null;
        if (null !== $mapping) {
            if (!$container->has('validator')) {
                throw new ServiceNotFoundException(
                    "The 'validator' service is not found. To use the 'InputValidator' you need to install the
                    Symfony Validator Component first. See: 'https://symfony.com/doc/current/validation.html'"
                );
            }
            $inputValidatorDefinition = $this->createAnonymousInputValidatorDefinition(
                $mapping['properties'] ?? [],
                $mapping['class'] ?? []
            );
            $validationGroups = $mapping['validationGroups'] ?? null;

            $resolverDefinition
                ->setArgument('$validator', $inputValidatorDefinition)
                ->setArgument('$validationGroups', $validationGroups);
        } elseif (in_array('$validator', $resolverDefinition->getArgument(1) ?? [])) {
            throw new InvalidArgumentException(
                'Unable to inject an instance of the InputValidator. No validation constraints provided. '.
                'Please remove the "validator" argument from the list of dependencies of your resolver '.
                'or provide validation configs.'
            );
        }
    }

    private function createAnonymousResolverDefinitionForExpression(string $expressionString): Definition
    {
        $requiredInputValidator = ExpressionLanguage::expressionContainsVar('validator', $expressionString);
        $requiredInputValidatorErrors = ExpressionLanguage::expressionContainsVar('errors', $expressionString);

        $definition = (new Definition(ResolverExpression::class))
            ->addArgument(
                (new Definition(Expression::class))->addArgument($expressionString)
            )
        ;

        return (new Definition(Closure::class))
            ->setFactory([new Reference(ResolverFactory::class), 'createResolver'])
            ->setArguments([
                $definition,
                [
                    '$resolverArgs',
                    new Reference(GlobalVariables::class),
                    new Reference('overblog_graphql.expression_language'),
                    $requiredInputValidator || $requiredInputValidatorErrors ? '$validator' : null,
                    $requiredInputValidatorErrors ? '$errors' : null,
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

        return (new Definition(Closure::class))
            ->setFactory([new Reference(ResolverFactory::class), 'createResolver'])
            ->setArguments([
                $this->resolverReference($resolver, $resolverRef, $isStatic),
                $this->resolveArgumentValues($container, $argumentMetadataFactory, $resolver, $bind, $configPath),
            ])
        ;
    }

    private function createAnonymousInputValidatorDefinition(
        array $propertiesMapping,
        array $classMapping
    ): Definition {
        return (new Definition(InputValidator::class))
                ->setArguments([
                    null,
                    new Reference('validator'),
                    new Reference(ValidatorFactory::class),
                    new Reference(TypeResolver::class),
                    array_map([$this, 'buildValidationRules'], $propertiesMapping),
                    $this->buildValidationRules($classMapping),
                ])
                ->addTag('overblog_graphql.input_validator')
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
                $argumentValues[] = $default[$i];
            } elseif (null !== $argument->getType() && isset($default[$argument->getType().' $'.$argument->getName()])) { // type and argument name
                $argumentValues[] = $default[$argument->getType().' $'.$argument->getName()];
            } elseif (null !== $argument->getType() && isset($default[$argument->getType()])) { // typehint instance of
                $argumentValues[] = $default[$argument->getType()];
            } elseif (isset($default['$'.$argument->getName()])) { // default values (graphql arguments)
                $argumentValues[] = $default['$'.$argument->getName()];
            } elseif (null !== $argument->getType() && $container->has($argument->getType())) { // service
                $argumentValues[] = new Reference($argument->getType());
            } elseif ($argument->hasDefaultValue() || (null !== $argument->getType() && $argument->isNullable() && !$argument->isVariadic())) { // default value from signature
                $argumentValues[] = $argument->hasDefaultValue() ? $argument->getDefaultValue() : null;
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

    protected function buildValidationRules(array $mapping): array
    {
        /**
         * @var array  $constraints
         * @var string $link
         * @var array  $cascade
         * @phpstan-ignore-next-line
         */
        extract($mapping);

        $rules = [];

        if (!empty($link)) {
            if (false === strpos($link, '::')) {
                // e.g.: App\Entity\Droid
                $rules['link'] = $link;
            } else {
                // e.g. App\Entity\Droid::$id
                $rules['link'] = $this->normalizeLink($link);
            }
        }

        if (!empty($cascade)) {
            $rules['cascade'] = $this->buildCascade($cascade);
        }

        if (!empty($constraints)) {
            // If there are only constraints, dont use additional nesting
            if (empty($rules)) {
                $rules = $this->buildConstraints($constraints);
            } else {
                $rules['constraints'] = $this->buildConstraints($constraints);
            }
        }

        return $rules;
    }

    /**
     * <code>
     * [
     *     ['Symfony\Component\Validator\Constraints\NotNull'],
     *     ['Symfony\Component\Validator\Constraints\Length', ['min' => 5, 'max' => 10]],
     *     ...
     * ]
     * </code>.
     *
     * @throws InvalidArgumentException
     */
    protected function buildConstraints(array $constraints = []): array
    {
        foreach ($constraints as $i => &$wrapper) {
            $fqcn = key($wrapper);
            $args = reset($wrapper);

            if (false === strpos($fqcn, '\\')) {
                $fqcn = "Symfony\Component\Validator\Constraints\\$fqcn";
            }

            if (!class_exists($fqcn)) {
                throw new InvalidArgumentException(sprintf('Constraint class "%s" doesn\'t exist.', $fqcn));
            }

            $wrapper = new Definition($fqcn);

            if (is_array($args)) {
                if (isset($args[0]) && is_array($args[0])) {
                    // Another instance?
                    $wrapper->setArguments([$this->buildConstraints($args)]);
                } else {
                    // Numeric or Assoc array?
                    $wrapper->setArguments([$args]);
                }
            } elseif (null !== $args) {
                $wrapper->setArguments([$args]);
            }
        }

        return $constraints;
    }

    /**
     * @throws InvalidArgumentException
     */
    protected function buildCascade(array $cascade): array
    {
        /**
         * @var string $referenceType
         * @var array  $groups
         * @var bool   $isCollection
         *
         * @phpstan-ignore-next-line
         */
        extract($cascade);
        $result = [];

        if (!empty($groups)) {
            $result['groups'] = $groups;
        }

        if (isset($isCollection)) { // @phpstan-ignore-line
            $result['isCollection'] = $isCollection;
        }

        if (isset($referenceType)) { // @phpstan-ignore-line
            $type = trim($referenceType, '[]!');

            if (in_array($type, [Type::STRING, Type::INT, Type::FLOAT, Type::BOOLEAN, Type::ID])) {
                throw new InvalidArgumentException('Cascade validation cannot be applied to built-in types.');
            }
            $result['referenceType'] = $referenceType;
        }

        return $result;
    }

    protected function normalizeLink(string $link): array
    {
        [$fqcn, $classMember] = explode('::', $link);

        if ('$' === $classMember[0]) {
            return [$fqcn, ltrim($classMember, '$'), 'property'];
        } elseif (')' === substr($classMember, -1)) {
            return [$fqcn, rtrim($classMember, '()'), 'getter'];
        } else {
            return [$fqcn, $classMember, 'member'];
        }
    }
}
