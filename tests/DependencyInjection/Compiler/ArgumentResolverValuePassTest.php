<?php

declare(strict_types=1);

namespace Overblog\GraphQLBundle\Tests\DependencyInjection\Compiler;

use Closure;
use Overblog\GraphQLBundle\Definition\Argument;
use Overblog\GraphQLBundle\Definition\ArgumentFactory;
use Overblog\GraphQLBundle\DependencyInjection\Compiler\ArgumentResolverValuePass;
use Overblog\GraphQLBundle\Resolver\ResolverFactory;
use Overblog\GraphQLBundle\Tests\DependencyInjection\Compiler\fixtures\Foo;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

class ArgumentResolverValuePassTest extends TestCase
{
    private ContainerBuilder $container;
    private ArgumentResolverValuePass $compilerPass;

    public function setUp(): void
    {
        $this->container = new ContainerBuilder();
        $this->container->setParameter('kernel.bundles', []);
        $this->container->setParameter('kernel.debug', false);
        $this->container->register(stdClass::class, stdClass::class);
        $this->container->set(ResolverFactory::class, new ResolverFactory(new ArgumentFactory(Argument::class)));
        $this->compilerPass = new ArgumentResolverValuePass();
    }

    public function tearDown(): void
    {
        unset($this->container, $this->compilerPass);
    }

    /**
     * @dataProvider resolverDataProvider
     */
    public function testResolver(string $class, ?string $method, array $expectedDefinitionArgs): void
    {
        $configs = [
            'foo' => [
                'type' => 'object',
                'config' => [
                    'fields' => [$method => ['resolver' => ['method' => $method ? $class.'::'.$method : $class]]],
                ],
            ],
        ];

        $this->processCompilerPass($configs);
        $resolverId = $this->container->getParameter('overblog_graphql_types.config')['foo']['config']['fields'][$method]['resolver']['id'];

        $expectedDefinition = (new Definition(Closure::class, $expectedDefinitionArgs))
            ->setFactory([new Reference(ResolverFactory::class), 'createResolver'])
            ->addTag('overblog_graphql.resolver');

        $this->assertEquals(
            $expectedDefinition,
            $this->container->getDefinition($resolverId),
        );
        $resolver = $this->container->get($resolverId);
        $this->assertInstanceOf(Closure::class, $resolver);
    }

    public function resolverDataProvider(): iterable
    {
        yield [Foo::class, 'noArgs', [[Foo::class, 'noArgs'], []]];
        yield [
            Foo::class,
            'valueWithTypehint',
            [
                [Foo::class, 'valueWithTypehint'],
                ['value' => '$value'],
            ],
        ];
        yield [
            Foo::class,
            'allNotOrder',
            [
                [Foo::class, 'allNotOrder'],
                [
                    'value' => '$value',
                    'info' => '$info',
                    'args' => '$args',
                ],
            ],
        ];
        yield [
            Foo::class,
            'infoTypehint',
            [
                [Foo::class, 'infoTypehint'],
                ['test' => '$info'],
            ],
        ];
        yield [
            Foo::class,
            'infoWithoutTypehint',
            [
                [Foo::class, 'infoWithoutTypehint'],
                ['info' => '$info'],
            ],
        ];
        yield [
            Foo::class,
            'defaultValue',
            [
                [Foo::class, 'defaultValue'],
                ['default' => []],
            ],
        ];
        yield [
            Foo::class,
            'staticMethod',
            [
                    Foo::class.'::staticMethod',
                    ['args' => '$args'],
            ],
        ];
        yield [
            Foo::class,
            null,
            [
                Foo::class,
                [],
            ],
        ];
        yield [
            Foo::class,
            'injection',
            [
                [Foo::class, 'injection'],
                [
                    'value' => '$value',
                    'object' => new Reference(stdClass::class),
                ],
            ],
        ];
    }

    private function processCompilerPass(array $configs, ?ArgumentResolverValuePass $compilerPass = null): void
    {
        $container = $container ?? $this->container;
        $compilerPass = $compilerPass ?? $this->compilerPass;
        $container->setParameter('overblog_graphql_types.config', $configs);
        $compilerPass->process($container);
    }
}
