<?php

declare(strict_types=1);

namespace Overblog\GraphQLBundle\GraphQL\Relay\Mutation;

use Closure;
use GraphQL\Executor\Promise\Promise;
use GraphQL\Executor\Promise\PromiseAdapter;
use GraphQL\Type\Definition\ResolveInfo;
use Overblog\GraphQLBundle\Definition\ArgumentFactory;
use Overblog\GraphQLBundle\Definition\ArgumentInterface;
use Overblog\GraphQLBundle\Definition\Resolver\AliasedInterface;
use Overblog\GraphQLBundle\Definition\Resolver\ResolverInterface;
use function is_array;
use function is_object;

final class MutationFieldResolver implements ResolverInterface, AliasedInterface
{
    private PromiseAdapter $promiseAdapter;

    public function __construct(PromiseAdapter $promiseAdapter)
    {
        $this->promiseAdapter = $promiseAdapter;
    }

    /**
     * @param mixed $payload
     */
    public function __invoke(ArgumentInterface $args, $payload): Promise
    {
        return $this->promiseAdapter->createFulfilled($payload)
            ->then(function ($payload) use ($args) {
                $this->setObjectOrArrayValue($payload, 'clientMutationId', $args['input']['clientMutationId'] ?? null);

                return $payload;
            });
    }

    /**
     * {@inheritdoc}
     */
    public static function getAliases(): array
    {
        return ['__invoke' => 'relay_mutation_field'];
    }

    /**
     * @param object|array $objectOrArray
     * @param mixed        $value
     */
    private function setObjectOrArrayValue(&$objectOrArray, string $fieldName, $value): void
    {
        if (is_array($objectOrArray)) {
            $objectOrArray[$fieldName] = $value;
        } elseif (is_object($objectOrArray)) {
            $objectOrArray->$fieldName = $value;
        }
    }
}
