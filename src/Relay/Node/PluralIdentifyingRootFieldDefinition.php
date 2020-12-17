<?php

declare(strict_types=1);

namespace Overblog\GraphQLBundle\Relay\Node;

use InvalidArgumentException;
use Overblog\GraphQLBundle\Definition\Builder\MappingInterface;
use Overblog\GraphQLBundle\ExpressionLanguage\ExpressionLanguage;
use function array_key_exists;
use function is_string;
use function json_encode;
use function sprintf;
use function strpos;
use function substr;

final class PluralIdentifyingRootFieldDefinition implements MappingInterface
{
    public function toMappingDefinition(array $config): array
    {
        if (!isset($config['argName']) || !is_string($config['argName'])) {
            throw new InvalidArgumentException('A valid pluralIdentifyingRoot "argName" config is required.');
        }

        if (!isset($config['inputType']) || !is_string($config['inputType'])) {
            throw new InvalidArgumentException('A valid pluralIdentifyingRoot "inputType" config is required.');
        }

        if (!isset($config['outputType']) || !is_string($config['outputType'])) {
            throw new InvalidArgumentException('A valid pluralIdentifyingRoot "outputType" config is required.');
        }

        if (!array_key_exists('resolveSingleInput', $config)) {
            throw new InvalidArgumentException('PluralIdentifyingRoot "resolveSingleInput" config is required.');
        }

        $argName = $config['argName'];

        return $this->prependResolve([
            'type' => "[${config['outputType']}]",
            'args' => [$argName => ['type' => "[${config['inputType']}!]!"]],
        ], $config['resolveSingleInput']);
    }

    /**
     * @param array|string $resolveSingleInput
     */
    private function prependResolve(array $config, $resolveSingleInput): array
    {
        if (is_string($resolveSingleInput) && ExpressionLanguage::stringHasTrigger($resolveSingleInput)) {
            $config['resolve'] = sprintf(
                "@=resolver('relay_plural_identifying_field', [args, context, info, %s])",
                ExpressionLanguage::unprefixExpression($resolveSingleInput)
            );
        } else {
            // todo(mcg-web): deal with resolve when using DI syntax
        }

        return $config;
    }
}
