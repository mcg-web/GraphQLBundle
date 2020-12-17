<?php

declare(strict_types=1);

namespace Overblog\GraphQLBundle\Relay\Mutation;

use InvalidArgumentException;
use Overblog\GraphQLBundle\Definition\Builder\MappingInterface;
use Overblog\GraphQLBundle\ExpressionLanguage\ExpressionLanguage;
use function rtrim;
use function sprintf;

final class MutationFieldDefinition implements MappingInterface
{
    private const KEY_MUTATE_GET_PAYLOAD = 'mutateAndGetPayload';

    public function toMappingDefinition(array $config): array
    {
        return $this->prependResolve([
            'type' => $this->extractPayloadType($config),
            'args' => [
                'input' => ['type' => $this->extractInputType($config)],
            ],
        ], $this->extractMutateAndGetPayload($config));
    }

    /**
     * @param string|array $mutateAndGetPayload
     */
    private function prependResolve(array $config, $mutateAndGetPayload): array
    {
        if (is_string($mutateAndGetPayload) && ExpressionLanguage::stringHasTrigger($mutateAndGetPayload)) {
            $mutateAndGetPayload = preg_replace(
                '/\bvalue\b/',
                'args[\'input\']',
                ExpressionLanguage::unprefixExpression($mutateAndGetPayload)
            );

            $config['resolve'] = "@=resolver('relay_mutation_field', [args, $mutateAndGetPayload])";
        } else {
            // todo(mcg-web): deal with resolve when using DI syntax
        }

        return $config;
    }

    /**
     * @return string|array
     */
    private function extractMutateAndGetPayload(array $config)
    {
        if (empty($config[self::KEY_MUTATE_GET_PAYLOAD])) {
            throw new InvalidArgumentException(sprintf('Mutation "%s" config is required.', self::KEY_MUTATE_GET_PAYLOAD));
        }

        return $config[self::KEY_MUTATE_GET_PAYLOAD];
    }

    private function extractPayloadType(array $config): ?string
    {
        return is_string($config['payloadType'] ?? null) ? $config['payloadType'] : null;
    }

    private function extractInputType(array $config): ?string
    {
        return is_string($config['inputType'] ?? null) ? rtrim($config['inputType'], '!').'!' : null;
    }
}
