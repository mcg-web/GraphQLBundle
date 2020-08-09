<?php

declare(strict_types=1);

namespace Overblog\GraphQLBundle\DependencyInjection\Compiler;

use GraphQL\Type\Definition\Type;
use Overblog\GraphQLBundle\Resolver\TypeResolver;
use Overblog\GraphQLBundle\Validator\InputValidator;
use Overblog\GraphQLBundle\Validator\ValidatorFactory;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;
use Symfony\Component\DependencyInjection\Reference;
use function is_array;

class ResolverInputValidatorArgumentPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $configs = $container->getParameter('overblog_graphql_types.config');
        foreach ($configs as $typeName => &$config) {
            foreach ($config['config']['fields'] ?? [] as $fieldName => $field) {
                if (isset($field['resolver']['id'])) {
                    $resolverDefinition = $container->getDefinition($field['resolver']['id']);
                    $this->addValidatorRequirementsToResolverDefinition(
                        $container,
                        $resolverDefinition,
                        $config['config'],
                        $config['config']['fields'][$fieldName],
                    );
                }
            }
        }
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

    private function buildValidationRules(array $mapping): array
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
     *     new Definition('Symfony\Component\Validator\Constraints\NotNull'),
     *     new Definition('Symfony\Component\Validator\Constraints\Length', ['min' => 5, 'max' => 10]),
     *     ...
     * ]
     * </code>.
     *
     * @throws InvalidArgumentException
     */
    private function buildConstraints(array $constraints = []): array
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
    private function buildCascade(array $cascade): array
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

    private function normalizeLink(string $link): array
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
