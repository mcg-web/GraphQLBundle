<?php

declare(strict_types=1);

namespace Overblog\GraphQLBundle\Tests\Resolver\Argument;

use ArrayObject;
use GraphQL\Type\Definition\ResolveInfo;
use Overblog\GraphQLBundle\Definition\Argument;
use Overblog\GraphQLBundle\Definition\GlobalVariables;
use Overblog\GraphQLBundle\ExpressionLanguage\ExpressionLanguage;
use Overblog\GraphQLBundle\Generator\Converter\ExpressionConverter;
use Overblog\GraphQLBundle\Resolver\ResolverFactory;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionException;
use stdClass;

class ResolverFactoryTest extends TestCase
{
    private ResolverFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new ResolverFactory();
    }

    /**
     * @param mixed $expected
     * @dataProvider argumentValuesProvider
     */
    public function testCreateResolve($expected, array $argumentValues, array $resolverArgs): void
    {
        $resolver = $this->factory->createResolver(fn ($v) => $v, $argumentValues);
        $this->assertSame($expected, $resolver(...$resolverArgs));
    }

    public function testCreateExpressionResolve(): void
    {
        $resolver = $this->factory->createExpressionResolver('[value, args, context, info]', new ExpressionConverter(new ExpressionLanguage()), new GlobalVariables());

        $expected = [new stdClass(), new Argument(), new ArrayObject(), $this->createMock(ResolveInfo::class)];
        $this->assertSame($expected, $resolver(...$expected));
    }

    /**
     * @dataProvider canUseDefaultArgumentsProvider
     */
    public function testCanUseDefaultArguments(array $resolverArgs, bool $expected): void
    {
        $actual = $this->invokeMethod($this->factory, 'canUseDefaultArguments', [$resolverArgs]);

        if ($expected) {
            $this->assertTrue($actual);
        } else {
            $this->assertFalse($actual);
        }
    }

    public function canUseDefaultArgumentsProvider(): iterable
    {
        yield [['$value', '$args', '$context', '$info'], true];
        yield [['$value', '$args', '$context'], true];
        yield [['$value', '$args'], true];
        yield [['$value'], true];
        yield [[], true];
        yield [['$value', '$args', '$info', '$context'], false];
        yield [['$info'], false];
        yield [['$context'], false];
        yield [['$args'], false];
        yield [['$value', '$args', '$context', '$info', '$foo'], false];
        yield [['$value', '$args', '$context', new stdClass()], false];
        yield [['$value', null, '$context', '$info'], false];
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

    /**
     * @return mixed
     *
     * @throws ReflectionException
     */
    private function invokeMethod(object $object, string $methodName, array $args = [])
    {
        $reflection = new ReflectionClass(get_class($object));
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($object, $args);
    }
}
