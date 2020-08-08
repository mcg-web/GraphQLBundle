<?php

declare(strict_types=1);

namespace Overblog\GraphQLBundle\DependencyInjection\Compiler;

use Closure;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;
use Murtukov\PHPCodeGenerator\Instance;
use Overblog\GraphQLBundle\Definition\ArgumentInterface;
use Overblog\GraphQLBundle\Definition\GlobalVariables;
use Overblog\GraphQLBundle\Error\ResolveErrors;
use Overblog\GraphQLBundle\ExpressionLanguage\ExpressionLanguage;
use Overblog\GraphQLBundle\Generator\Converter\ExpressionConverter;
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
                    if (!empty($field['resolver']['method'])) {
                        $methodName = $field['resolver']['method'];
                        list($resolverClass, $resolverMethod) = explode('::', $methodName, 2) + [null, null];
                        $resolverDefinition = $this->createAnonymousResolverDefinitionForMethod(
                            $container, $argumentMetadataFactory, $resolverClass, $resolverMethod, $field['resolver']['bind'] ?? []
                        );

                        $requiredInputValidator = in_array('$validator', $resolverDefinition->getArgument(1));
                        $requiredInputValidatorErrors = in_array('$errors', $resolverDefinition->getArgument(1));
                    } else {
                        $expression = $field['resolver']['expression'];
                        $resolverDefinition = $this->createAnonymousResolverDefinitionForExpression($expression);

                        $requiredInputValidator = ExpressionLanguage::expressionContainsVar('validator', $expression);
                        $requiredInputValidatorErrors = ExpressionLanguage::expressionContainsVar('errors', $expression);
                    }
                    $mapping = $this->restructureObjectValidationConfig(
                        $config['config'],
                        $config['config']['fields'][$name]
                    );
                    $inputValidatorDefinition = null;
                    $validationGroups = null;
                    if (null !== $mapping) {
                        $inputValidatorDefinition = $this->createAnonymousInputValidatorDefinition(
                            $mapping['properties'] ?? [],
                            $mapping['class'] ?? []
                        );
                        $validationGroups = $mapping['validationGroups'] ?? null;

                        $resolverDefinition
                            ->setArgument('$validator', $inputValidatorDefinition)
                            ->setArgument('$validationGroups', $validationGroups);
                    }

                    $container->setDefinition($id = $this->generateAnonymousResolverId($resolverDefinition), $resolverDefinition);

                    // TODO(mcg-web): use id directly in TypeBuilder
                    $config['config']['fields'][$name]['resolve'] = sprintf('@=res(\'%s\', [value, args, context, info])', $id);
                    $config['config']['fields'][$name]['resolver']['id'] = $id;
                }
            }
        }
        $container->setParameter('overblog_graphql_types.config', $configs);
    }

    private function generateAnonymousResolverId(Definition $definition): string
    {
        return sprintf(
            'overblog_graphql.anonymous_resolver_%s',
            substr(sha1(serialize($definition)), 0, 12)
        );
    }

    private function createAnonymousResolverDefinitionForExpression(string $expressionString): Definition
    {
        return (new Definition(Closure::class))
            ->setFactory([new Reference(ResolverFactory::class), 'createExpressionResolver'])
            ->setArguments([
                $expressionString,
                new Reference(ExpressionConverter::class),
                new Reference(GlobalVariables::class),
            ])
            ->addTag('overblog_graphql.resolver')
        ;
    }

    private function createAnonymousResolverDefinitionForMethod(
        ContainerBuilder $container,
        ArgumentMetadataFactory $argumentMetadataFactory,
        string $resolverClass,
        ?string $resolverMethod,
        array $bind
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
                $this->resolveArgumentValues($container, $argumentMetadataFactory, $resolver, $bind),
            ])
            ->addTag('overblog_graphql.resolver')
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
         * @phpstan-ignore-next-line
         */
        extract($cascade);
        $result = [];

        if (!empty($groups)) {
            $result['groups'] = $groups;
        }

        if (isset($isCollection)) {
            $result['isCollection'] = $isCollection;
        }

        if (isset($referenceType)) {
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
