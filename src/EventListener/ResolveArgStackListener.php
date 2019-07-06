<?php

declare(strict_types=1);

namespace Overblog\GraphQLBundle\EventListener;

use GraphQL\Type\Definition\ObjectType;
use Overblog\GraphQLBundle\Event\TypeLoadedEvent;
use Overblog\GraphQLBundle\Resolver\ResolveArgStack;

final class ResolveArgStackListener
{
    private $resolveArgStack;

    public function __construct(ResolveArgStack $resolveArgStack)
    {
        $this->resolveArgStack = $resolveArgStack;
    }

    public function onTypeLoaded(TypeLoadedEvent $event): void
    {
        $type = $event->getType();
        if ($type instanceof ObjectType) {
            $fields = $type->config['fields'];
            $type->resolveFieldFn = $this->resolveArgStack->lazyPush($type->resolveFieldFn);
            $type->config['fields'] = function () use ($fields) {
                if (\is_callable($fields)) {
                    $fields = $fields();
                }

                foreach ($fields as $key => &$field) {
                    $field['resolve'] = $this->resolveArgStack->lazyPush($field['resolve']);
                }

                return $fields;
            };
        }
    }
}
