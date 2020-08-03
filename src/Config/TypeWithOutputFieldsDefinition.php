<?php

declare(strict_types=1);

namespace Overblog\GraphQLBundle\Config;

use Overblog\GraphQLBundle\ExpressionLanguage\ExpressionLanguage;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\NodeDefinition;
use function is_string;

abstract class TypeWithOutputFieldsDefinition extends TypeDefinition
{
    protected function outputFieldsSection(): NodeDefinition
    {
        /** @var ArrayNodeDefinition $node */
        $node = self::createNode('fields');

        $node->isRequired()->requiresAtLeastOneElement();

        $prototype = $node->useAttributeAsKey('name', false)->prototype('array');

        /** @phpstan-ignore-next-line */
        $prototype
            ->beforeNormalization()
                // Allow field type short syntax (Field: Type => Field: {type: Type})
                ->ifTrue(fn ($options) => is_string($options))
                ->then(fn ($options) => ['type' => $options])
            ->end()
            ->beforeNormalization()
                ->ifTrue(fn ($options) => !empty($options['resolve']) && empty($options['resolver']))
                ->then(function ($options) {
                    if (is_callable($options['resolve'])) {
                        if (is_array($options['resolve'])) {
                            $options['resolver']['method'] = join('::', $options['resolve']);
                        } else {
                            $options['resolver']['method'] = $options['resolve'];
                        }
                    } elseif (is_string($options['resolve'])) {
                        $options['resolver']['expression'] = ExpressionLanguage::stringHasTrigger($options['resolve']) ?
                            ExpressionLanguage::unprefixExpression($options['resolve']) :
                            json_encode($options['resolve']);
                    } else {
                        $options['resolver']['expression'] = json_encode($options['resolve']);
                    }

                    return $options;
                })
            ->end()
            ->beforeNormalization()
                ->ifTrue(fn ($options) => array_key_exists('resolve', $options))
                ->then(function ($options) {
                    unset($options['resolve']);

                    return $options;
                })
            ->end()
            ->validate()
                ->ifTrue(fn (array $v) => !empty($v['resolver']) && !empty($v['resolve']))
                ->thenInvalid('"resolver" and "resolve" should not be use together in "%s".')
            ->end()

            ->validate()
                // Remove empty entries
                ->always(function ($value) {
                    if (empty($value['validationGroups'])) {
                        unset($value['validationGroups']);
                    }

                    if (empty($value['args'])) {
                        unset($value['args']);
                    }

                    return $value;
                })
            ->end()
            ->children()
                ->append($this->typeSection())
                ->append($this->validationSection(self::VALIDATION_LEVEL_CLASS))
                ->arrayNode('validationGroups')
                    ->beforeNormalization()
                        ->castToArray()
                    ->end()
                    ->prototype('scalar')
                        ->info('List of validation groups')
                    ->end()
                ->end()
                ->arrayNode('args')
                    ->info('Array of possible type arguments. Each entry is expected to be an array with following keys: name (string), type')
                    ->useAttributeAsKey('name', false)
                    ->prototype('array')
                        // Allow arg type short syntax (Arg: Type => Arg: {type: Type})
                        ->beforeNormalization()
                            ->ifTrue(fn ($options) => is_string($options))
                            ->then(fn ($options) => ['type' => $options])
                        ->end()
                        ->children()
                            ->append($this->typeSection(true))
                            ->append($this->descriptionSection())
                            ->append($this->defaultValueSection())
                            ->append($this->validationSection(self::VALIDATION_LEVEL_PROPERTY))
                        ->end()
                    ->end()
                ->end()
                ->append($this->resolverSection('resolver', 'GraphQL value resolver'))
                ->append($this->descriptionSection())
                ->append($this->deprecationReasonSection())
                ->variableNode('access')
                    ->info('Access control to field (expression language can be used here)')
                ->end()
                ->variableNode('public')
                    ->info('Visibility control to field (expression language can be used here)')
                ->end()
                ->variableNode('complexity')
                    ->info('Custom complexity calculator.')
                ->end()
            ->end();

        return $node;
    }

    protected function fieldsBuilderSection(): ArrayNodeDefinition
    {
        $node = self::createNode('builders');

        $prototype = $node->prototype('array');

        $prototype
            ->children()
                ->variableNode('builder')->isRequired()->end()
                ->variableNode('builderConfig')->end()
            ->end();

        return $node;
    }
}
