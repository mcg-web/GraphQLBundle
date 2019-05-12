<?php

declare(strict_types=1);

namespace Overblog\GraphQLBundle\Tests\Config\Parser;

use Overblog\GraphQLBundle\Config\Parser\GraphQL\ASTConverter\CustomScalarNode;
use Overblog\GraphQLBundle\Config\Parser\GraphQLParser;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;

class GraphQLParserTest extends TestCase
{
    public function testParse(): void
    {
        $fileName = __DIR__.'/fixtures/graphql/schema.graphql';
        $expected = include __DIR__.'/fixtures/graphql/schema.php';

        $this->assertContainerAddFileToResources($fileName);
        $config = GraphQLParser::parse(new \SplFileInfo($fileName), $this->containerBuilder);
        $this->assertSame($expected, self::cleanConfig($config));
    }

    public function testParseEmptyFile(): void
    {
        $fileName = __DIR__.'/fixtures/graphql/empty.graphql';

        $this->assertContainerAddFileToResources($fileName);

        $config = GraphQLParser::parse(new \SplFileInfo($fileName), $this->containerBuilder);
        $this->assertSame([], $config);
    }

    public function testParseInvalidFile(): void
    {
        $fileName = __DIR__.'/fixtures/graphql/invalid.graphql';
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(\sprintf('An error occurred while parsing the file "%s"', $fileName));
        GraphQLParser::parse(new \SplFileInfo($fileName), $this->containerBuilder);
    }

    public function testCustomScalarTypeDefaultFieldValue(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Config entry must be override with ResolverMap to be used.');
        CustomScalarNode::mustOverrideConfig();
    }
}
