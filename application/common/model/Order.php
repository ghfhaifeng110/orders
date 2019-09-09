<?php

namespace app\common\model;

use think\Hook;
use app\common\model\store\shop\Order as ShopOrder;
use app\common\service\Order as OrderService;
use app\common\enum\OrderType as OrderTypeEnum;
use app\common\enum\DeliveryType as DeliveryTypeEnum;

/**
 * 订单模型
 * Class Order
 * @package app\common\model
 */
class Order extends BaseModel
{
    protected $name = 'order';

    /**
     * 追加字段
     * @var array
     */
    protected $append = [
        'state_text',   // 售后单状态文字描述
    ];

    /**
     * 订单模型初始化
     */
    public static function init()
    {
        parent::init();
        // 监听订单处理事件
        $static = new static;
        Hook::listen('order', $static);
    }

    /**
     * 拼团订单状态文字描述
     * @param $value
     * @param $data
     * @return string
     */
    public function getStateTextAttr($value, $data)
    {
        // 订单状态
        if (in_array($data['order_status'], [20, 30])) {
            $orderStatus = [20 => '已取消', 30 => '已完成'];
            return $orderStatus[$data['order_status']];
        }
        // 付款状态
        if ($data['pay_status'] == 10) {
            return '待付款';
        }
        // 订单类型：单独购买
        if ($data['delivery_status'] == 10) {
            return '已付款，待发货';
        }
        if ($data['receipt_status'] == 10) {
            return '已发货，待收货';
        }
        return $value;
    }

    /**
     * 订单商品列表
     * @return \think\model\relation\HasMany
     */
    public function goods()
    {
        return $this->hasMany('OrderGoods');
    }

    /**
     * 关联订单收货地址表
     * @return \think\model\relation\HasOne
     */
    public function address()
    {
        return $this->hasOne('OrderAddress');
    }

    /**
     * 关联自提门店表
     * @return \think\model\relation\BelongsTo
     */
    public function extractShop()
    {
        $module = self::getCalledModule() ?: 'common';
        return $this->belongsTo("app\\{$module}\\model\\store\\Shop", 'extract_shop_id');
    }

    /**
     * 关联门店店员表
     * @return \think\model\relation\BelongsTo
     */
    public function extractClerk()
    {
        $module = self::getCalledModule() ?: 'common';
        return $this->belongsTo("app\\{$module}\\model\\store\\shop\\Clerk", 'extract_clerk_id');
    }

    /**
     * 关联用户表
     * @return \think\model\relation\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo('User');
    }

    /**
     * 关联物流公司表
     * @return \think\model\relation\BelongsTo
     */
    public function express()
    {
        return $this->belongsTo('Express');
    }

    /**
     * 改价金额（差价）
     * @param $value
     * @return array
     */
    public function getUpdatePriceAttr($value)
    {
        return [
            'symbol' => $value < 0 ? '-' : '+',
            'value' => sprintf('%.2f', abs($value))
        ];
    }

    /**
     * 付款状态
     * @param $value
     * @return array
     */
    public function getPayStatusAttr($value)
    {
        $status = [10 => '待付款', 20 => '已付款'];
        return ['text' => $status[$value], 'value' => $value];
    }

    /**
     * 发货状态
     * @param $value
     * @return array
     */
    public function getDeliveryStatusAttr($value)
    {
        $status = [10 => '待发货', 20 => '已发货'];
        return ['text' => $status[$value], 'value' => $value];
    }

    /**
     * 收货状态
     * @param $value
     * @return array
     */
    public function getReceiptStatusAttr($value)
    {
        $status = [10 => '待收货', 20 => '已收货'];
        return ['text' => $status[$value], 'value' => $value];
    }

    /**
     * 收货状态
     * @param $value
     * @return array
     */
    public function getOrderStatusAttr($value)
    {
        $status = [10 => '进行中', 20 => '已取消', 21 => '待取消', 30 => '已完成'];
        return ['text' => $status[$value], 'value' => $value];
    }

    /**
     * 配送方式
     * @param $value
     * @return array
     */
    public function getDeliveryTypeAttr($value)
    {
        $types = DeliveryTypeEnum::getTypeName();
        return ['text' => $types[$value], 'value' => $value];
    }

    /**
     * 生成订单号
     * @return string
     */
    protected function orderNo()
    {
        return OrderService::createOrderNo();
    }

    /**
     * 订单详情
     * @param $where
     * @return null|static
     * @throws \think\exception\DbException
     */
    public static function detail($where)
    {
        is_array($where) ? $filter = $where : $filter['order_id'] = (int)$where;
        return self::get($filter, [
            'goods' => ['image'],
            'address',
            'express',
            'extract_shop.logo',
            'extract_clerk'
        ]);
    }

    /**
     * 批量获取订单列表
     * @param $orderIds
     * @param array $with 关联查询
     * @return false|\PDOStatement|string|\think\Collection|array
     */
    public static function getListByIds($orderIds, $with = [])
    {
        $model = new static;
        !empty($with) && $model->with($with);
        $data = $model->where('order_id', 'in', $orderIds)->select();
        if (!$data->isEmpty()) {
            $list = [];
            foreach ($data as $key => &$item) {
                $list[$item['order_id']] = $item;
            }
            return $list;
        }
        return $data;
    }

    /**
     * 确认核销
     * @param int $extractClerkId 核销员id
     * @return bool|false|int
     */
    public function extract($extractClerkId)
    {
        if (
            $this['pay_status']['value'] != 20
            || $this['delivery_type']['value'] != DeliveryTypeEnum::EXTRACT
            || $this['delivery_status']['value'] == 20
            || in_array($this['order_status']['value'], [20, 21])
        ) {
            $this->error = '该订单不满足核销条件';
            return false;
        }
        $this->transaction(function () use ($extractClerkId) {
            // 更新订单状态：已发货、已收货
            $this->save([
                'extract_clerk_id' => $extractClerkId,  // 核销员
                'delivery_status' => 20,
                'delivery_time' => time(),
                'receipt_status' => 20,
                'receipt_time' => time(),
                'order_status' => 30
            ]);
            // 新增订单核销记录
            ShopOrder::add(
                $this['order_id'],
                $this['extract_shop_id'],
                $this['extract_clerk_id'],
                OrderTypeEnum::MASTER
            );
        });
        return true;
    }

}
