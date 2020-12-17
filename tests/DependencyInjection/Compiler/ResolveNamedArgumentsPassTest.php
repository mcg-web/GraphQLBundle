<?php

declare(strict_types=1);

namespace Overblog\GraphQLBundle\Tests\DependencyInjection\Compiler;

use Overblog\GraphQLBundle\Definition\GlobalVariables;
use Overblog\GraphQLBundle\DependencyInjection\Compiler\ResolveNamedArgumentsPass;
use Overblog\GraphQLBundle\ExpressionLanguage\ExpressionLanguage;
use Overblog\GraphQLBundle\ExpressionLanguage\ResolverExpression;
use Overblog\GraphQLBundle\Generator\Converter\ExpressionConverter;
use Overblog\GraphQLBundle\Resolver\Resolver;
use Overblog\GraphQLBundle\Tests\DependencyInjection\Compiler\fixtures\Foo;
use Overblog\GraphQLBundle\Tests\Generator\TypeGenerator;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

class ResolveNamedArgumentsPassTest extends TestCase
{
    private ContainerBuilder $container;
    private ResolveNamedArgumentsPass $compilerPass;

    public function setUp(): void
    {
        $this->container = new ContainerBuilder();
        $this->container->setParameter('kernel.bundles', []);
        $this->container->setParameter('kernel.debug', false);
        $this->container->register(stdClass::class, stdClass::class);
        $this->container->set('overblog_graphql.expression_language', new ExpressionLanguage());
        $this->container->set(GlobalVariables::class, new GlobalVariables());
        $this->container->set(ExpressionConverter::class, new ExpressionConverter(new ExpressionLanguage()));
        $this->compilerPass = new ResolveNamedArgumentsPass();
    }

    public function tearDown(): void
    {
        unset($this->container, $this->compilerPass);
    }

    /**
     * @dataProvider resolverDataProvider
     */
    public function testCreateResolver(string $class, ?string $method, array $expectedDefinitionArgs): void
    {
        $configs = [
            'foo' => [
                'type' => 'object',
                'config' => [
                    'fields' => ['test' => ['resolver' => ['method' => $method ? $class.'::'.$method : $class]]],
                ],
            ],
        ];

        $this->processCompilerPass($configs);
        $this->assertDefinition(
            $expectedDefinitionArgs,
            $this->container->getParameter('overblog_graphql_types.config')['foo']['config']['fields']['test']['resolver']['id'],
        );
    }

    public function testCreateExpressionResolver(): void
    {
        $expressionString = 'value';
        $configs = [
            'foo' => [
                'type' => 'object',
                'config' => [
                    'fields' => ['testExpression' => ['resolver' => ['expression' => $expressionString]]],
                ],
            ],
        ];

        $this->processCompilerPass($configs);
        $this->assertDefinition(
            [
                new Definition(
                    ResolverExpression::class,
                    [new Reference('overblog_graphql.expression_language'), $expressionString]
                ),
                [
                    'value' => '$value',
                    'args' => '$args',
                    'context' => '$context',
                    'info' => '$info',
                    TypeGenerator::GLOBAL_VARS => new Reference(GlobalVariables::class),
                ],
            ],
            $this->container->getParameter('overblog_graphql_types.config')['foo']['config']['fields']['testExpression']['resolver']['id'],
        );
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
                ['info' => '$info', 'args' => '$args', 'value' => '$value'],
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
                ['value' => '$value', 'object' => new Reference(stdClass::class)],
            ],
        ];
    }

    private function assertDefinition(array $expectedDefinitionArgs, string $resolverId): void
    {
        $expectedDefinition = (new Definition(Resolver::class, $expectedDefinitionArgs))
            ->setPublic(true);

        $this->assertEquals(
            $expectedDefinition,
            $this->container->getDefinition($resolverId),
        );
        $resolver = $this->container->get($resolverId);
        $this->assertInstanceOf(Resolver::class, $resolver);
    }

    private function processCompilerPass(array $configs, ?ResolveNamedArgumentsPass $compilerPass = null): void
    {
        $container = $container ?? $this->container;
        $compilerPass = $compilerPass ?? $this->compilerPass;
        $container->setParameter('overblog_graphql_types.config', $configs);
        $compilerPass->process($container);
    }
}
