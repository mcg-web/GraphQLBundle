<?php

declare(strict_types=1);

namespace Overblog\GraphQLBundle\Tests\ExpressionLanguage\ExpressionFunction\GraphQL;

use Overblog\GraphQLBundle\ExpressionLanguage\ExpressionFunction\GraphQL\Mutation;
use Overblog\GraphQLBundle\Resolver\MutationResolver;

class MutationTest extends AbstractProxyResolverTest
{
    protected function getFunctions()
    {
        return [new Mutation(), new Mutation('mut')];
    }

    public function nameDataProvider(): iterable
    {
        yield ['mutation'];
        yield ['mut'];
    }

    protected function getOriginalClassName(): string
    {
        return MutationResolver::class;
    }

    protected function getGlobalVariableName(): string
    {
        return 'mutationResolver';
    }
}
