<?php

declare(strict_types=1);

namespace Overblog\GraphQLBundle\Config;

use Overblog\GraphQLBundle\ExpressionLanguage\ExpressionLanguage;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\ScalarNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\Builder\VariableNodeDefinition;
use function is_array;
use function is_int;
use function is_string;
use function preg_match;

abstract class TypeDefinition
{
    public const VALIDATION_LEVEL_CLASS = 0;
    public const VALIDATION_LEVEL_PROPERTY = 1;

    abstract public function getDefinition(): ArrayNodeDefinition;

    final protected function __construct()
    {
    }

    /**
     * @return static
     */
    public static function create(): self
    {
        return new static();
    }

    protected function resolveTypeSection(): VariableNodeDefinition
    {
        return self::createNode('resolveType', 'variable');
    }

    protected function nameSection(): ScalarNodeDefinition
    {
        /** @var ScalarNodeDefinition $node */
        $node = self::createNode('name', 'scalar');

        $node
            ->isRequired()
            ->validate()
                ->ifTrue(fn ($name) => !preg_match('/^[_a-z][_0-9a-z]*$/i', $name))
                ->thenInvalid('Invalid type name "%s". (see http://spec.graphql.org/June2018/#sec-Names)')
            ->end()
        ;

        return $node;
    }

    protected function defaultValueSection(): VariableNodeDefinition
    {
        return self::createNode('defaultValue', 'variable');
    }

    protected function validationSection(int $level): ArrayNodeDefinition
    {
        /** @var ArrayNodeDefinition $node */
        $node = self::createNode('validation', 'array');

        /** @phpstan-ignore-next-line */
        $node
            // allow shorthands
            ->beforeNormalization()
                ->always(function ($value) {
                    if (is_string($value)) {
                        // shorthand: cascade or link
                        return 'cascade' === $value ? ['cascade' => null] : ['link' => $value];
                    }

                    if (is_array($value)) {
                        foreach ($value as $k => $a) {
                            if (!is_int($k)) {
                                // validation: { link: ... , constraints: ..., cascade: ... }
                                return $value;
                            }
                        }
                        // validation: [list of constraints]
                        return ['constraints' => $value];
                    }

                    return [];
                })
            ->end()
            ->children()
                ->scalarNode('link')
                    ->validate()
                        ->ifTrue(function ($link) use ($level) {
                            if (self::VALIDATION_LEVEL_PROPERTY === $level) {
                                return !preg_match('/^(?:\\\\?[A-Za-z][A-Za-z\d]+)*[A-Za-z\d]+::(?:[$]?[A-Za-z][A-Za-z_\d]+|[A-Za-z_\d]+\(\))$/m', $link);
                            } else {
                                return !preg_match('/^(?:\\\\?[A-Za-z][A-Za-z\d]+)*[A-Za-z\d]$/m', $link);
                            }
                        })
                        ->thenInvalid('Invalid link provided: "%s".')
                    ->end()
                ->end()
                ->variableNode('constraints')->end()
            ->end();

        // Add the 'cascade' option if it's a property level validation section
        if (self::VALIDATION_LEVEL_PROPERTY === $level) {
            /** @phpstan-ignore-next-line */
            $node
                ->children()
                    ->arrayNode('cascade')
                        ->children()
                            ->arrayNode('groups')
                                ->beforeNormalization()
                                    ->castToArray()
                                ->end()
                                ->scalarPrototype()->end()
                            ->end()
                        ->end()
                    ->end()
                ->end();
        }

        return $node;
    }

    protected function descriptionSection(): ScalarNodeDefinition
    {
        /** @var ScalarNodeDefinition $node */
        $node = self::createNode('description', 'scalar');

        return $node;
    }

    protected function deprecationReasonSection(): ScalarNodeDefinition
    {
        /** @var ScalarNodeDefinition $node */
        $node = self::createNode('deprecationReason', 'scalar');

        $node->info('Text describing why this field is deprecated. When not empty - field will not be returned by introspection queries (unless forced)');

        return $node;
    }

    protected function typeSection(bool $isRequired = false): ScalarNodeDefinition
    {
        /** @var ScalarNodeDefinition $node */
        $node = self::createNode('type', 'scalar');

        $node->info('One of internal or custom types.');

        if ($isRequired) {
            $node->isRequired();
        }

        return $node;
    }

    protected function resolverSection(string $name, string $info): ArrayNodeDefinition
    {
        /** @var ArrayNodeDefinition $node */
        $node = self::createNode($name);
        /** @phpstan-ignore-next-line */
        $node
            ->info($info)
            ->validate()
                ->ifTrue(fn (array $v) => !empty($v['method']) && !empty($v['expression']))
                ->thenInvalid('"method" and "expression" should not be use together.')
            ->end()
            ->validate()
                ->ifTrue(fn (array $v) => !empty($v['expression']) && !empty($v['bind']))
                ->thenInvalid('"expression" does not support "bind" options.')
            ->end()
            ->beforeNormalization()
                // Allow short syntax
                ->ifTrue(fn ($options) => is_string($options) && ExpressionLanguage::stringHasTrigger($options))
                ->then(fn ($options) => ['expression' => ExpressionLanguage::unprefixExpression($options)])
            ->end()
            ->beforeNormalization()
                ->ifTrue(fn ($options) => is_string($options) && !ExpressionLanguage::stringHasTrigger($options))
                ->then(fn ($options) => ['method' => $options])
            ->end()
            ->beforeNormalization()
                // clean expression
                ->ifTrue(fn ($options) => isset($options['expression']) && is_string($options['expression']) && ExpressionLanguage::stringHasTrigger($options['expression']))
                ->then(function ($options) {
                    $options['expression'] = ExpressionLanguage::unprefixExpression($options['expression']);

                    return $options;
                })
            ->end()
            ->children()
                ->scalarNode('method')->end()
                ->scalarNode('expression')->end()
                ->arrayNode('bind')
                    ->useAttributeAsKey('name')
                    ->prototype('variable')->end()
                ->end()
            ->end()
        ;

        return $node;
    }

    /**
     * @return mixed
     *
     * @internal
     */
    protected static function createNode(string $name, string $type = 'array')
    {
        return (new TreeBuilder($name, $type))->getRootNode();
    }
}
