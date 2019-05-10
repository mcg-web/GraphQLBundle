<?php

declare(strict_types=1);

namespace Overblog\GraphQLBundle\Config\Parser\GraphQL\ASTConverter;

use GraphQL\Language\AST\Node;
use GraphQL\Language\AST\SchemaDefinitionNode;

class SchemaNode implements NodeInterface
{
    /**
     * @param SchemaDefinitionNode|Node $node
     *
     * @return array
     */
    public static function toConfig(Node $node): array
    {
        $config = [];

        foreach ($node->operationTypes as $operationType) {
            $config[$operationType->operation] = $operationType->type->name->value;
        }

        return [
            'type' => 'schema',
            'config' => $config,
        ];
    }
}
