<?php

declare(strict_types=1);

namespace Overblog\GraphQLBundle\Config;

class SchemaTypeDefinition extends TypeDefinition
{
    public function getDefinition()
    {
        $node = self::createNode('_schema_config');

        $node
            ->children()
                ->scalarNode('name')->isRequired()->end()
                ->scalarNode('query')->isRequired()->end()
                ->scalarNode('mutation')->defaultNull()->end()
                ->scalarNode('subscription')->defaultNull()->end()
                ->arrayNode('resolver_maps')
                    ->defaultValue([])
                    ->prototype('scalar')->end()
                ->end()
                ->arrayNode('types')
                    ->defaultValue([])
                    ->prototype('scalar')->end()
                ->end()
            ->end();

        return $node;
    }
}
