<?php

declare(strict_types=1);

namespace Overblog\GraphQLBundle\Tests\Resolver;

class Toto
{
    public const PRIVATE_PROPERTY_WITH_GETTER_VALUE = 'IfYouWantMeUseMyGetter';
    public const PRIVATE_PROPERTY_WITH_GETTER2_VALUE = 'IfYouWantMeUseMyGetter2';
    public const PRIVATE_PROPERTY_WITHOUT_GETTER = 'ImNotAccessibleFromOutside:D';

    private $privatePropertyWithoutGetter = self::PRIVATE_PROPERTY_WITHOUT_GETTER;
    private $privatePropertyWithGetter = self::PRIVATE_PROPERTY_WITH_GETTER_VALUE;
    private $private_property_with_getter2 = self::PRIVATE_PROPERTY_WITH_GETTER2_VALUE;
    public $name = 'public';
    private $enabled = true;

    /**
     * @return string
     */
    public function getPrivatePropertyWithGetter()
    {
        return $this->privatePropertyWithGetter;
    }

    /**
     * @return string
     */
    public function getPrivatePropertyWithGetter2()
    {
        return $this->private_property_with_getter2;
    }

    public function getPrivatePropertyWithoutGetterUsingCallBack()
    {
        return function () {
            return $this->privatePropertyWithoutGetter;
        };
    }

    public function resolve()
    {
        return \func_get_args();
    }

    /**
     * @return bool
     */
    public function isEnabled() {
        return $this->enabled;
    }
}
