<?php

declare(strict_types=1);

namespace Overblog\GraphQLBundle\Resolver;

use ArrayObject;
use GraphQL\Type\Definition\ResolveInfo;
use Overblog\GraphQLBundle\Definition\ArgumentInterface;

final class ResolverArgs
{
    /** @var mixed */
    private $value;

    private ArgumentInterface $args;

    private ArrayObject $context;

    private ResolveInfo $info;

    /**
     * @param mixed $value
     */
    public function __construct(
        $value,
        ArgumentInterface $args,
        ArrayObject $context,
        ResolveInfo $info
    ) {
        $this->value = $value;
        $this->args = $args;
        $this->context = $context;
        $this->info = $info;
    }

    /**
     * @return mixed
     */
    public function getValue()
    {
        return $this->value;
    }

    public function getArgs(): ?ArgumentInterface
    {
        return $this->args;
    }

    public function getContext(): ?ArrayObject
    {
        return $this->context;
    }

    public function getInfo(): ?ResolveInfo
    {
        return $this->info;
    }

    public function toArray(bool $named = false): array
    {
        if ($named) {
            return [
                'value' => $this->value,
                'args' => $this->args,
                'context' => $this->context,
                'info' => $this->info,
            ];
        } else {
            return [
                $this->value,
                $this->args,
                $this->context,
                $this->info,
            ];
        }
    }
}
