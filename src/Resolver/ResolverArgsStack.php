<?php

declare(strict_types=1);

namespace Overblog\GraphQLBundle\Resolver;

final class ResolverArgsStack
{
    private ResolverArgs $currentResolverArgs;

    public function __construct(?ResolverArgs $currentResolverArgs = null)
    {
        if (null !== $currentResolverArgs) {
            $this->currentResolverArgs = $currentResolverArgs;
        }
    }

    public function getCurrentResolverArgs(): ?ResolverArgs
    {
        return $this->currentResolverArgs;
    }

    public function setCurrentResolverArgs(ResolverArgs $currentResolverArgs): self
    {
        $this->currentResolverArgs = $currentResolverArgs;

        return $this;
    }
}
