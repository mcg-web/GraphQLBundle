<?php

declare(strict_types=1);

namespace Overblog\GraphQLBundle\Tests\ExpressionLanguage\ExpressionFunction\GraphQL;

use Overblog\GraphQLBundle\ExpressionLanguage\ExpressionFunction\GraphQL\Resolver;
use Overblog\GraphQLBundle\Resolver\ResolverResolver;

class ResolverTest extends AbstractProxyResolverTest
{
    protected function getFunctions()
    {
        return [new Resolver(), new Resolver('res')];
    }

    public function nameDataProvider(): iterable
    {
        yield ['resolver'];
        yield ['res'];
    }

    protected function getOriginalClassName(): string
    {
        return ResolverResolver::class;
    }

    protected function getGlobalVariableName(): string
    {
        return 'resolverResolver';
    }
}
