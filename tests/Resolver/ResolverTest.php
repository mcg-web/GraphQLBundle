<?php

declare(strict_types=1);

namespace Overblog\GraphQLBundle\Tests\Resolver\Argument;

use ArrayObject;
use GraphQL\Type\Definition\ResolveInfo;
use Overblog\GraphQLBundle\Definition\Argument;
use Overblog\GraphQLBundle\Resolver\Resolver;
use PHPUnit\Framework\TestCase;
use stdClass;

class ResolverTest extends TestCase
{
    /**
     * @param mixed $expected
     * @dataProvider argumentValuesProvider
     */
    public function testResolve($expected, array $argumentValues, array $resolverArgs): void
    {
        $resolver = new Resolver(fn ($v) => $v, $argumentValues);
        $payload = $resolver->resolve(...$resolverArgs);
        $this->assertSame($expected, $payload);
    }

    /**
     * @param mixed $expected
     * @dataProvider argumentValuesProvider
     */
    public function testInvoke($expected, array $argumentValues, array $resolverArgs): void
    {
        $resolver = new Resolver(fn ($v) => $v, $argumentValues);
        $payload = $resolver(...$resolverArgs);
        $this->assertSame($expected, $payload);
    }

    public function argumentValuesProvider(): iterable
    {
        $resolverArgs = [$value = 'foo', $args = new Argument(), $context = new ArrayObject(), $info = $this->createMock(ResolveInfo::class)];
        $object = new stdClass();

        yield [$value, ['$value'], $resolverArgs];
        yield [$args, ['$args'], $resolverArgs];
        yield [$context, ['$context'], $resolverArgs];
        yield [$info, ['$info'], $resolverArgs];
        yield [$object, [$object], $resolverArgs];
    }
}
