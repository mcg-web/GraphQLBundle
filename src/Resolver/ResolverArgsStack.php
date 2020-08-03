<?php

declare(strict_types=1);

namespace Overblog\GraphQLBundle\Resolver;

use ArrayObject;
use GraphQL\Type\Definition\ResolveInfo;
use Overblog\GraphQLBundle\Definition\ArgumentInterface;

final class ResolverArgsStack
{
    /** @var mixed */
    private $currentValue;

    private ArgumentInterface $currentArgs;

    private ArrayObject $currentContext;

    private ResolveInfo $currentInfo;

    /**
     * @return mixed
     */
    public function getCurrentValue()
    {
        return $this->currentValue;
    }

    /**
     * @param mixed $currentValue
     */
    public function setCurrentValue($currentValue): self
    {
        $this->currentValue = $currentValue;

        return $this;
    }

    public function getCurrentArgs(): ?ArgumentInterface
    {
        return $this->currentArgs;
    }

    public function setCurrentArgs(ArgumentInterface $currentArgs): self
    {
        $this->currentArgs = $currentArgs;

        return $this;
    }

    public function getCurrentContext(): ?ArrayObject
    {
        return $this->currentContext;
    }

    public function setCurrentContext(ArrayObject $currentContext): self
    {
        $this->currentContext = $currentContext;

        return $this;
    }

    public function getCurrentInfo(): ?ResolveInfo
    {
        return $this->currentInfo;
    }

    public function setCurrentInfo(ResolveInfo $currentInfo): self
    {
        $this->currentInfo = $currentInfo;

        return $this;
    }
}
