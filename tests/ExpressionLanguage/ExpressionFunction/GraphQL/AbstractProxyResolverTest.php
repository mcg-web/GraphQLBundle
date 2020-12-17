<?php

declare(strict_types=1);

namespace Overblog\GraphQLBundle\Tests\ExpressionLanguage\ExpressionFunction\GraphQL;

use Overblog\GraphQLBundle\Definition\GlobalVariables;
use Overblog\GraphQLBundle\Generator\TypeGenerator;
use Overblog\GraphQLBundle\Tests\ExpressionLanguage\TestCase;

abstract class AbstractProxyResolverTest extends TestCase
{
    /**
     * @dataProvider nameDataProvider
     */
    public function testEvaluator(string $name): void
    {
        $this->expressionLanguage->evaluate(
            $name.'("foo")',
            $this->getExpressionLanguageEvaluatorValues()
        );
    }

    /**
     * @dataProvider nameDataProvider
     */
    public function testEvaluatorWithArgs(string $name): void
    {
        $this->expressionLanguage->evaluate(
            $name.'("foo", ["bar"])',
            $this->getExpressionLanguageEvaluatorValues(['bar'])
        );
    }

    private function getExpressionLanguageEvaluatorValues(array $args = []): array
    {
        // @phpstan-ignore-next-line
        $resolver = $this->createMock($this->getOriginalClassName());
        $resolver->expects($this->once())
            ->method('resolve')
            ->with(['foo', $args]);
        $globalVars = new GlobalVariables([$this->getGlobalVariableName() => $resolver]);

        return [TypeGenerator::GLOBAL_VARS => $globalVars];
    }

    abstract public function nameDataProvider(): iterable;

    abstract protected function getOriginalClassName(): string;

    abstract protected function getGlobalVariableName(): string;
}
