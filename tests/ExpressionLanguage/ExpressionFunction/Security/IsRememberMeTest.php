<?php

declare(strict_types=1);

namespace Overblog\GraphQLBundle\Tests\ExpressionLanguage\ExpressionFunction\Security;

use Overblog\GraphQLBundle\Definition\GlobalVariables;
use Overblog\GraphQLBundle\ExpressionLanguage\ExpressionFunction\Security\IsRememberMe;
use Overblog\GraphQLBundle\Tests\ExpressionLanguage\TestCase;

class IsRememberMeTest extends TestCase
{
    protected function getFunctions()
    {
        return [new IsRememberMe()];
    }

    public function testEvaluator(): void
    {
        $security = $this->getSecurityIsGrantedWithExpectation(
            'IS_AUTHENTICATED_REMEMBERED',
            $this->any()
        );
        $globalVariable = new GlobalVariables(['security' => $security]);

        $isRememberMe = $this->expressionLanguage->evaluate('isRememberMe()', ['globalVariable' => $globalVariable]);
        $this->assertTrue($isRememberMe);
    }

    public function testIsRememberMe(): void
    {
        $this->assertExpressionCompile('isRememberMe()', 'IS_AUTHENTICATED_REMEMBERED');
    }
}
