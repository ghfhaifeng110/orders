<?php

namespace app\common\enum;

/**
 * 配送方式枚举类
 * Class DeliveryType
 * @package app\common\enum
 */
class DeliveryType extends EnumBasics
{
    // 快递配送
    const EXPRESS = 10;

    // 上门自提
    const EXTRACT = 20;

    /**
     * 获取配送方式名称
     * @return array
     */
    public static function getTypeName()
    {
        return [
            self::EXPRESS => '快递配送',
            self::EXTRACT => '上门自提',
        ];
    }

}