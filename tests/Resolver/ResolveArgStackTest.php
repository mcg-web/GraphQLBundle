<?php

declare(strict_types=1);

namespace Overblog\GraphQLBundle\Tests\Resolver;

use GraphQL\Type\Definition\ResolveInfo;
use Overblog\GraphQLBundle\Resolver\ResolveArg;
use Overblog\GraphQLBundle\Resolver\ResolveArgStack;
use PHPUnit\Framework\TestCase;

class ResolveArgStackTest extends TestCase
{
    public function testGetCurrentResolveArg(): void
    {
        $resolveArgStack = new ResolveArgStack();
        $this->assertNull($resolveArgStack->getCurrentResolveArg());

        $resolveArg = ResolveArg::create('foo', ['bar'], ['baz'], $this->getResolveInfoMock());

        $resolveArgStack->push($resolveArg);
        $this->assertSame($resolveArg, $resolveArgStack->getCurrentResolveArg());
    }

    public function testGetCurrentResolveArgWithLazyPush(): void
    {
        $resolveArgStack = new ResolveArgStack();
        $this->assertNull($resolveArgStack->getCurrentResolveArg());

        $lazyResolve = $resolveArgStack->lazyPush(function (): void {
        });
        $this->assertNull($resolveArgStack->getCurrentResolveArg());
        $lazyResolve('foo', ['bar'], ['baz'], $resolveInfo = $this->getResolveInfoMock());
        $resolveArg = $resolveArgStack->getCurrentResolveArg();

        $this->assertSame('foo', $resolveArg->getValue());
        $this->assertSame($resolveInfo, $resolveArg->getInfo());
        $this->assertSame(['bar'], $resolveArg->getArgs());
        $this->assertSame(['baz'], $resolveArg->getContext());
    }

    public function testGetParentResolveArg(): void
    {
        $resolveArgStack = new ResolveArgStack();
        $this->assertNull($resolveArgStack->getParentResolveArg());

        $parentResolveArg = ResolveArg::create('foo', ['bar'], ['baz'], $this->getResolveInfoMock());

        $resolveArgStack->push($parentResolveArg);
        $this->assertNull($resolveArgStack->getParentResolveArg());

        $firstSubResolveArg = ResolveArg::create('bar', ['baz'], ['foo'], $this->getResolveInfoMock());

        $resolveArgStack->push($firstSubResolveArg);
        $this->assertSame($parentResolveArg, $resolveArgStack->getParentResolveArg());

        $secondSubResolveArg = ResolveArg::create('baz', ['foo'], ['bar'], $this->getResolveInfoMock());

        $resolveArgStack->push($secondSubResolveArg);
        $this->assertSame($firstSubResolveArg, $resolveArgStack->getParentResolveArg());
    }

    private function getResolveInfoMock(): ResolveInfo
    {
        return $this->getMockBuilder(ResolveInfo::class)
            ->disableOriginalConstructor()
            ->getMock();
    }
}
