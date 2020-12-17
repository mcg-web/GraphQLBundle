<?php

declare(strict_types=1);

namespace Overblog\GraphQLBundle\Relay\Node;

use InvalidArgumentException;
use Overblog\GraphQLBundle\Definition\Builder\MappingInterface;
use Overblog\GraphQLBundle\ExpressionLanguage\ExpressionLanguage;
use function is_string;
use function strpos;
use function substr;

final class NodeFieldDefinition implements MappingInterface
{
    public function toMappingDefinition(array $config): array
    {
        $config['idFetcher'] = $config['idFetcher'] ?? null;
        if (!is_string($config['idFetcher']) && !is_array($config['idFetcher'])) {
            throw new InvalidArgumentException('Node "idFetcher" config is invalid.');
        }

        $nodeInterfaceType = isset($config['nodeInterfaceType']) && is_string($config['nodeInterfaceType']) ? $config['nodeInterfaceType'] : null;

        return $this->prependResolve([
            'description' => 'Fetches an object given its ID',
            'type' => $nodeInterfaceType,
            'args' => [
                'id' => ['type' => 'ID!', 'description' => 'The ID of an object'],
            ],
        ], $config['idFetcher']);
    }

    /**
     * @param string|array $idFetcher
     */
    private function prependResolve(array $config, $idFetcher): array
    {
        if (is_string($idFetcher) && ExpressionLanguage::stringHasTrigger($idFetcher)) {
            $config['resolve'] = preg_replace('/\bvalue\b/', 'args[\'id\']', $idFetcher);
        } else {
            // todo(mcg-web): deal with resolve when using DI syntax
        }

        return $config;
    }
}
