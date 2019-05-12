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
                ->scalarNode('query')->isRequired()->end()
                ->scalarNode('mutation')->defaultNull()->end()
                ->scalarNode('subscription')->defaultNull()->end()
            ->end();

        return $node;
    }
}
