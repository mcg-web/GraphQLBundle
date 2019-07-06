<?php

declare(strict_types=1);

namespace Overblog\GraphQLBundle\Resolver;

use GraphQL\Type\Definition\ResolveInfo;
use Overblog\GraphQLBundle\Definition\ArgumentInterface;

class ResolveArg
{
    private $value;

    private $args;

    private $context;

    private $info;

    public function __construct($value, $args, $context, ResolveInfo $info)
    {
        $this->value = $value;
        $this->args = $args;
        $this->context = $context;
        $this->info = $info;
    }

    public static function create($value, $args, $context, ResolveInfo $info): self
    {
        return new static($value, $args, $context, $info);
    }

    /**
     * @return mixed
     */
    public function getValue()
    {
        return $this->value;
    }

    /**
     * @return array|ArgumentInterface
     */
    public function getArgs()
    {
        return $this->args;
    }

    /**
     * @return \ArrayObject|array|null
     */
    public function getContext()
    {
        return $this->context;
    }

    /**
     * @return ResolveInfo
     */
    public function getInfo(): ResolveInfo
    {
        return $this->info;
    }
}
