<?php

declare(strict_types=1);

namespace Overblog\GraphQLBundle\DependencyInjection\Compiler;

use GraphQL\Type\Definition\Type;
use Overblog\GraphQLBundle\ExpressionLanguage\ExpressionLanguage;
use Overblog\GraphQLBundle\Validator\InputValidatorFactory;
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
        $typeValidationConfigs = [];

        $configs = $container->getParameter('overblog_graphql_types.config');
        foreach ($configs as $typeName => $config) {
            $typeName = $config['config']['name'] ?? $typeName;
            $typeValidationConfig = $this->buildTypeValidationConfig(
                $config['type'],
                $config['config'],
            );
            if (null !== $typeValidationConfig) {
                $typeValidationConfigs[$typeName] = $typeValidationConfig;
            }
            foreach ($config['config']['fields'] ?? [] as $fieldName => $field) {
                if (isset($field['resolver']['id'])) {
                    $resolverDefinition = $container->getDefinition($field['resolver']['id']);
                    if (!empty($field['resolver']['expression'])) {
                        $requiredInputValidator = ExpressionLanguage::expressionContainsVar('validator', $field['resolver']['expression']);
                        $requiredInputValidatorErrors = ExpressionLanguage::expressionContainsVar('errors', $field['resolver']['expression']);
                        $handlerArgs = $resolverDefinition->getArgument(1);
                        $handlerArgs['validator'] = $requiredInputValidator || $requiredInputValidatorErrors ? '$validator' : null;
                        $handlerArgs['errors'] = $requiredInputValidatorErrors ? '$errors' : null;
                        $resolverDefinition->setArgument(1, $handlerArgs);
                    }
                    if (isset($typeValidationConfig['fields'][$fieldName])) {
                        $resolverDefinition->addMethodCall(
                            'addArgumentResolver',
                            [
                                [new Reference(InputValidatorFactory::class), 'createArgs'],
                            ]
                        );
                    } elseif (in_array('$validator', $resolverDefinition->getArgument(1) ?? [])) {
                        throw new InvalidArgumentException(
                            'Unable to inject an instance of the InputValidator. No validation constraints provided. '.
                            'Please remove the "validator" argument from the list of dependencies of your resolver '.
                            'or provide validation configs.'
                        );
                    }
                }
            }
        }

        if (!empty($typeValidationConfigs)) {
            if (!$container->has('validator')) {
                throw new ServiceNotFoundException(
                    "The 'validator' service is not found. To use the 'InputValidator' you need to install the
                    Symfony Validator Component first. See: 'https://symfony.com/doc/current/validation.html'"
                );
            }

            $container->register(InputValidatorFactory::class, InputValidatorFactory::class)
                ->setAutowired(true)
                ->setBindings(['$typeValidationConfigs' => $typeValidationConfigs]);
        }
    }

    private function buildTypeValidationConfig(string $type, array $typeConfig): ?array
    {
        $typeValidationConfig = null;

        if (isset($typeConfig['validation'])) {
            $typeValidationConfig['validationRules'] = $this->buildValidationRules($typeConfig['validation']);
        }

        $fieldsValidationConfig = null;

        foreach ($typeConfig['fields'] ?? [] as $fieldName => $fieldConfig) {
            $properties = [];
            if (
                'input-object' === $type &&
                !empty($fieldConfig['validation'])
            ) {
                $validation = $fieldConfig['validation'];
                if (!empty($fieldConfig['validation']['cascade'])) {
                    $validation['cascade']['isCollection'] = '[' === $fieldConfig['type'][0];
                    $validation['cascade']['referenceType'] = trim($fieldConfig['type'], '[]!');
                }
                $fieldsValidationConfig[$fieldName]['validationRules'] = $this->buildValidationRules($validation);
            }

            foreach ($fieldConfig['args'] ?? [] as $argName => $arg) {
                if (empty($arg['validation'])) {
                    continue;
                }

                $properties[$argName] = $arg['validation'];

                if (empty($arg['validation']['cascade'])) {
                    continue;
                }

                $properties[$argName]['cascade']['isCollection'] = '[' === $arg['type'][0];
                $properties[$argName]['cascade']['referenceType'] = trim($arg['type'], '[]!');
            }

            // Merge class and field constraints
            $classValidation = $typeConfig['validation'] ?? [];

            if (!empty($fieldConfig['validation'])) {
                $classValidation = array_replace_recursive($classValidation, $fieldConfig['validation']);
            }

            if (!empty($classValidation)) {
                $fieldsValidationConfig[$fieldName]['class'] = $this->buildValidationRules($classValidation);
            }

            // properties
            $properties = array_filter(array_map([$this, 'buildValidationRules'], $properties));

            if (!empty($properties)) {
                $fieldsValidationConfig[$fieldName]['properties'] = $properties;
            }

            // validationGroups
            if (!empty($fieldConfig['validationGroups'])) {
                $fieldsValidationConfig[$fieldName]['validationGroups'] = $fieldConfig['validationGroups'];
            }
        }

        if (null !== $fieldsValidationConfig) {
            $typeValidationConfig['fields'] = $fieldsValidationConfig;
        }

        return $typeValidationConfig;
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
