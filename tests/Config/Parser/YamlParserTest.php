<?php

declare(strict_types=1);

namespace Overblog\GraphQLBundle\Tests\Config\Parser;

use Overblog\GraphQLBundle\Config\Parser\YamlParser;
use SplFileInfo;

class YamlParserTest extends TestCase
{
    public function testParseConstants(): void
    {
        $actual = (new YamlParser())->parseFiles(
            [new SplFileInfo(__DIR__.'/fixtures/yaml/constants.yml')],
            $this->containerBuilder
        );
        $this->assertSame([['value' => Constants::TWILEK]], $actual);
    }
}
