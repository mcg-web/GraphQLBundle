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
     * @dataProvider handlerArgsProvider
     */
    public function testInvoke($expected, array $handlerArgs, array $resolverArgs): void
    {
        $resolver = new Resolver(
            fn ($v) => $v,
            $handlerArgs
        );
        $this->assertSame($expected, $resolver(...$resolverArgs));
    }

    public function testAddArgumentResolver(): void
    {
        $resolver = new Resolver(
            function (stdClass $object, string $v) {
                $object->value = $v;

                return $object;
            },
            ['$object', '$value']
        );
        $expected = new stdClass();
        $resolver->addArgumentResolver(fn () => ['object' => $expected]);

        $actual = $resolver($value = 'foo', new Argument(), new ArrayObject(), $this->createMock(ResolveInfo::class));
        $this->assertSame($expected, $actual);
        $this->assertSame($value, $actual->value);
    }

    public function handlerArgsProvider(): iterable
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
