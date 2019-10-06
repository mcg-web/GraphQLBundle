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

use Overblog\GraphQLGenerator\Generator\Code\Closure;
use PHPUnit\Framework\TestCase;

class ClosureTest extends TestCase
{
    public function testGenerate(): void
    {
        $code = new Closure(['int $x', 'int $y'], '$r+= $x;');
        $code->getCode()
            ->append('// init result')
            ->prepend('$r = 0;')
            ->append(['$r += $y;', '', 'return $r;']);

        $this->assertSame(15, eval('$c = '.$code->generate().'; return $c(5, 10);'));
    }
}
