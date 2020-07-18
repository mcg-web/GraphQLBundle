<?php

declare(strict_types=1);

namespace Overblog\GraphQLBundle\Tests\DependencyInjection\Compiler\fixtures;

use GraphQL\Type\Definition\ResolveInfo;
use stdClass;

class Foo
{
    public function noArgs(): array
    {
        return func_get_args();
    }

    public function valueWithTypehint(Bar $value): array
    {
        return func_get_args();
    }

    // @phpstan-ignore-next-line
    public function allNotOrder(ResolveInfo $info, $args, $value): array
    {
        return func_get_args();
    }

    public function infoTypehint(ResolveInfo $test): array
    {
        return func_get_args();
    }

    // @phpstan-ignore-next-line
    public function infoWithoutTypehint($info): array
    {
        return func_get_args();
    }

    public function defaultValue(array $default = []): array
    {
        return func_get_args();
    }

    // @phpstan-ignore-next-line
    public static function staticMethod($args): array
    {
        return func_get_args();
    }

    // @phpstan-ignore-next-line
    public function injection($value, stdClass $object): array
    {
        return func_get_args();
    }

    public function __invoke(): array
    {
        return func_get_args();
    }
}
