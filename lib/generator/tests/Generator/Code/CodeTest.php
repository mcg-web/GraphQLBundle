<?php declare(strict_types=1);

/*
 * This file is part of the OverblogGraphQLPhpGenerator package.
 *
 * (c) Overblog <http://github.com/overblog/>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Overblog\GraphQLGenerator\Tests\Generator\Code;

use Overblog\GraphQLGenerator\Generator\Code\Code;
use PHPUnit\Framework\TestCase;

class CodeTest extends TestCase
{
    public function testPrepend(): void
    {
        $code = new Code('third');
        $code->prepend('first');
        $code->prepend('second', 1);

        $this->assertSame(['first', 'second', 'third'], $code->getLines());
        $this->assertSame("first\nsecond\nthird", $code->generate());
    }

    public function testAppend(): void
    {
        $code = new Code('first');
        $code->append('third');
        $code->append('second', 0);

        $this->assertSame(['first', 'second', 'third'], $code->getLines());
        $this->assertSame("first\nsecond\nthird", $code->generate());
    }
}
