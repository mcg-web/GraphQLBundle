<?php

declare(strict_types=1);

namespace Overblog\GraphQLBundle\Config\Parser;

use GraphQL\Language\AST\DefinitionNode;
use GraphQL\Language\AST\NodeKind;
use GraphQL\Language\Parser;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;

class GraphQLParser implements ParserInterface
{
    private const DEFINITION_TYPE_MAPPING = [
        NodeKind::OBJECT_TYPE_DEFINITION => 'object',
        NodeKind::INTERFACE_TYPE_DEFINITION => 'interface',
        NodeKind::ENUM_TYPE_DEFINITION => 'enum',
        NodeKind::UNION_TYPE_DEFINITION => 'union',
        NodeKind::INPUT_OBJECT_TYPE_DEFINITION => 'inputObject',
        NodeKind::SCALAR_TYPE_DEFINITION => 'customScalar',
        NodeKind::SCHEMA_DEFINITION => 'schema',
    ];

    /**
     * {@inheritdoc}
     */
    public static function parse(\SplFileInfo $file, ContainerBuilder $container, array $configs = []): array
    {
        $container->addResource(new FileResource($file->getRealPath()));
        $content = \trim(\file_get_contents($file->getPathname()));
        $typesConfig = [];

        // allow empty files
        if (empty($content)) {
            return [];
        }
        try {
            $ast = Parser::parse($content);
        } catch (\Exception $e) {
            throw new InvalidArgumentException(\sprintf('An error occurred while parsing the file "%s".', $file), $e->getCode(), $e);
        }

        foreach ($ast->definitions as $typeDef) {
            if (!isset($typeDef->kind) || !\in_array($typeDef->kind, \array_keys(self::DEFINITION_TYPE_MAPPING))) {
                self::throwUnsupportedDefinitionNode($typeDef);
            }
            $class = \sprintf('\\%s\\GraphQL\\ASTConverter\\%sNode', __NAMESPACE__, \ucfirst(self::DEFINITION_TYPE_MAPPING[$typeDef->kind]));
            $config = \call_user_func([$class, 'toConfig'], $typeDef);
            if (isset($typeDef->name->value)) {
                $typesConfig[$typeDef->name->value] = $config;
            } else {
                $typesConfig[] = $config;
            }
        }

        return $typesConfig;
    }

    private static function throwUnsupportedDefinitionNode(DefinitionNode $typeDef): void
    {
        $path = \explode('\\', \get_class($typeDef));
        throw new InvalidArgumentException(
            \sprintf(
                '%s definition is not supported right now.',
                \preg_replace('@DefinitionNode$@', '', \array_pop($path))
            )
        );
    }
}
