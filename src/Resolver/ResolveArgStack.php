<?php

declare(strict_types=1);

namespace Overblog\GraphQLBundle\Resolver;

class ResolveArgStack
{
    private $resolveArgs = [];

    public function push(ResolveArg $resolveArg): void
    {
        $this->resolveArgs[] = $resolveArg;
    }

    public function lazyPush(?callable $resolver): ?\Closure
    {
        if (null === $resolver) {
            return null;
        }

        return function (...$args) use ($resolver) {
            $this->push(ResolveArg::create(...$args));

            return $resolver(...$args);
        };
    }

    public function getCurrentResolveArg(): ?ResolveArg
    {
        return \end($this->resolveArgs) ?: null;
    }

    public function getParentResolveArg(): ?ResolveArg
    {
        return $this->resolveArgs[\count($this->resolveArgs) - 2] ?? null;
    }
}
