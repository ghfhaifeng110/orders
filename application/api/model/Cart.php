<?php

namespace app\api\model;

use think\Cache;
use app\api\model\store\Shop as ShopModel;
use app\common\enum\OrderType as OrderTypeEnum;
use app\common\enum\DeliveryType as DeliveryTypeEnum;
use app\common\service\delivery\Express as ExpressService;

/**
 * 购物车管理
 * Class Cart
 * @package app\api\model
 */
class Cart
{
    /* @var string $error 错误信息 */
    public $error = '';

    /* @var \think\Model|\think\Collection $user 用户信息 */
    private $user;

    /* @var int $user_id 用户id */
    private $user_id;

    /* @var int $wxapp_id 小程序商城id */
    private $wxapp_id;

    /* @var array $cart 购物车列表 */
    private $cart = [];

    /* @var bool $clear 是否清空购物车 */
    private $clear = false;

    /**
     * 构造方法
     * Cart constructor.
     * @param \think\Model|\think\Collection $user
     */
    public function __construct($user)
    {
        $this->user = $user;
        $this->user_id = $this->user['user_id'];
        $this->wxapp_id = $this->user['wxapp_id'];
        $this->cart = Cache::get('cart_' . $this->user_id) ?: [];
    }

    /**
     * 购物车列表 (含商品信息)
     * @param string $cartIds 购物车id集
     * @param int $delivery 配送方式
     * @param int $shop_id 自提门店id
     * @return array
     * @throws \think\Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function getList(
        $cartIds = null,
        $delivery = DeliveryTypeEnum::EXPRESS,
        $shop_id = 0
    )
    {
        // 返回的数据
        $returnData = [];
        // 获取购物车商品列表
        $goodsList = $this->getGoodsList($cartIds);
        // 订单商品总数量
        $orderTotalNum = array_sum(array_column($goodsList, 'total_num'));
        // 订单商品总金额
        $orderTotalPrice = array_sum(array_column($goodsList, 'total_price'));
        // 处理配送方式
        if ($delivery == DeliveryTypeEnum::EXPRESS) {
            $this->orderExpress($returnData, $goodsList, $orderTotalPrice);
        } elseif ($delivery == DeliveryTypeEnum::EXTRACT) {
            $shop_id > 0 && $returnData['extract_shop'] = ShopModel::detail($shop_id);
        }
        // 可用优惠券列表
        $couponList = UserCoupon::getUserCouponList($this->user['user_id'], $orderTotalPrice);
        return array_merge([
            'goods_list' => array_values($goodsList),         // 商品列表
            'order_total_num' => $orderTotalNum,              // 商品总数量
            'order_total_price' => sprintf('%.2f', $orderTotalPrice),   // 商品总金额 (不含运费)
            'order_pay_price' => $orderTotalPrice,            // 实际支付金额
            'delivery' => $delivery,                        // 配送类型
            'coupon_list' => array_values($couponList),     // 优惠券列表
            'address' => $this->user['address_default'],    // 默认地址
            'exist_address' => !$this->user['address']->isEmpty(),  // 是否存在收货地址
            'express_price' => '0.00',      // 配送费用
            'intra_region' => true,         // 当前用户收货城市是否存在配送规则中
            'extract_shop' => [],           // 自提门店信息
            'has_error' => $this->hasError(),
            'error_msg' => $this->getError(),
        ], $returnData);
    }

    /**
     * 订单配送-快递配送
     * @param $returnData
     * @param $goodsList
     * @param $orderTotalPrice
     */
    private function orderExpress(&$returnData, $goodsList, $orderTotalPrice)
    {
        // 当前用户收货城市id
        $cityId = $this->user['address_default'] ? $this->user['address_default']['city_id'] : null;
        // 初始化配送服务类
        $ExpressService = new ExpressService(
            $this->wxapp_id,
            $cityId,
            $goodsList,
            OrderTypeEnum::MASTER
        );
        // 获取不支持当前城市配送的商品
        $notInRuleGoods = $ExpressService->getNotInRuleGoods();
        // 验证商品是否在配送范围
        $intraRegion = $returnData['intra_region'] = $notInRuleGoods === false;
        if ($intraRegion == false) {
            $notInRuleGoodsName = $notInRuleGoods['goods_name'];
            $this->setError("很抱歉，您的收货地址不在商品 [{$notInRuleGoodsName}] 的配送范围内");
        } else {
            // 计算配送金额
            $ExpressService->setExpressPrice();
        }
        // 订单总运费金额
        $expressPrice = $returnData['express_price'] = $ExpressService->getTotalFreight();
        // 订单总金额 (含运费)
        $returnData['order_pay_price'] = bcadd($orderTotalPrice, $expressPrice, 2);
    }

    /**
     * 获取购物车列表
     * @param string|null $cartIds 购物车索引集 (为null时则获取全部)
     * @return array
     */
    private function getCartList($cartIds = null)
    {
        if (is_null($cartIds)) return $this->cart;
        $cartList = [];
        $indexArr = (strpos($cartIds, ',') !== false) ? explode(',', $cartIds) : [$cartIds];
        foreach ($indexArr as $index) {
            isset($this->cart[$index]) && $cartList[$index] = $this->cart[$index];
        }
        return $cartList;
    }

    /**
     * 获取购物车中的商品列表
     * @param $cartIds
     * @return array|bool
     * @throws \think\Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    private function getGoodsList($cartIds)
    {
        // 购物车商品列表
        $goodsList = [];
        // 获取购物车列表
        $cartList = $this->getCartList($cartIds);
        if (empty($cartList)) return $goodsList;
        // 购物车中所有商品id集
        $goodsIds = array_unique(array_column($cartList, 'goods_id'));
        // 获取并格式化商品数据
        $goodsData = [];
        foreach ((new Goods)->getListByIds($goodsIds) as $item) {
            $goodsData[$item['goods_id']] = $item;
        }
        // 格式化购物车数据列表
        foreach ($cartList as $cart) {
            // 判断商品不存在则自动删除
            if (!isset($goodsData[$cart['goods_id']])) {
                $this->delete($cart['goods_id'] . '_' . $cart['goods_sku_id']);
                continue;
            }
            /* @var Goods $goods */
            $goods = $goodsData[$cart['goods_id']];
            // 判断商品是否已删除
            if ($goods['is_delete']) {
                $this->delete($cart['goods_id'] . '_' . $cart['goods_sku_id']);
                continue;
            }
            // 商品sku信息
            $goods['goods_sku_id'] = $cart['goods_sku_id'];
            // 商品sku不存在则自动删除
            if (!$goods['goods_sku'] = $goods->getGoodsSku($cart['goods_sku_id'])) {
                $this->delete($cart['goods_id'] . '_' . $cart['goods_sku_id']);
                continue;
            }
            // 判断商品是否下架
            if ($goods['goods_status']['value'] != 10) {
                $this->setError('很抱歉，商品 [' . $goods['goods_name'] . '] 已下架');
            }
            // 判断商品库存
            if ($cart['goods_num'] > $goods['goods_sku']['stock_num']) {
                $this->setError('很抱歉，商品 [' . $goods['goods_name'] . '] 库存不足');
            }
            // 商品单价
            $goods['goods_price'] = $goods['goods_sku']['goods_price'];
            // 购买数量
            $goods['total_num'] = $cart['goods_num'];
            // 商品总价
            $goods['total_price'] = $total_price = bcmul($goods['goods_price'], $cart['goods_num'], 2);
            $goodsList[] = $goods->toArray();
        }
        return $goodsList;
    }

    /**
     * 添加购物车
     * @param $goods_id
     * @param $goods_num
     * @param $goods_sku_id
     * @return bool
     */
    public function add($goods_id, $goods_num, $goods_sku_id)
    {
        // 购物车商品索引
        $index = $goods_id . '_' . $goods_sku_id;
        // 商品信息
        $goods = Goods::detail($goods_id);
        // 判断商品是否下架
        if (!$goods || $goods['is_delete'] || $goods['goods_status']['value'] != 10) {
            $this->setError('很抱歉，商品信息不存在或已下架');
            return false;
        }
        // 商品sku信息
        $goods['goods_sku'] = $goods->getGoodsSku($goods_sku_id);
        // 判断商品库存
        $cartGoodsNum = $goods_num + (isset($this->cart[$index]) ? $this->cart[$index]['goods_num'] : 0);
        if ($cartGoodsNum > $goods['goods_sku']['stock_num']) {
            $this->setError('很抱歉，商品库存不足');
            return false;
        }
        $create_time = time();
        $data = compact('goods_id', 'goods_num', 'goods_sku_id', 'create_time');
        if (empty($this->cart)) {
            $this->cart[$index] = $data;
            return true;
        }
        isset($this->cart[$index]) ? $this->cart[$index]['goods_num'] = $cartGoodsNum : $this->cart[$index] = $data;
        return true;
    }

    /**
     * 减少购物车中某商品数量
     * @param $goods_id
     * @param $goods_sku_id
     */
    public function sub($goods_id, $goods_sku_id)
    {
        $index = $goods_id . '_' . $goods_sku_id;
        $this->cart[$index]['goods_num'] > 1 && $this->cart[$index]['goods_num']--;
    }

    /**
     * 删除购物车中指定商品
     * @param $cart_ids (支持字符串ID集)
     */
    public function delete($cart_ids)
    {
        $indexArr = strpos($cart_ids, ',') !== false
            ? explode(',', $cart_ids) : [$cart_ids];
        foreach ($indexArr as $index) {
            if (isset($this->cart[$index])) unset($this->cart[$index]);
        }
    }

    /**
     * 获取当前用户购物车商品总数量(含件数)
     * @return int
     */
    public function getTotalNum()
    {
        return array_sum(array_column($this->cart, 'goods_num'));
    }

    /**
     * 获取当前用户购物车商品总数量(不含件数)
     * @return int
     */
    public function getGoodsNum()
    {
        return count($this->cart);
    }

    /**
     * 析构方法
     * 将cart数据保存到缓存文件
     */
    public function __destruct()
    {
        $this->clear !== true && Cache::set('cart_' . $this->user_id, $this->cart, 86400 * 15);
    }

    /**
     * 清空当前用户购物车
     * @param null $cart_ids
     */
    public function clearAll($cart_ids = null)
    {
        if (is_null($cart_ids)) {
            $this->clear = true;
            Cache::rm('cart_' . $this->user_id);
        } else {
            $this->delete($cart_ids);
        }
    }

    /**
     * 设置错误信息
     * @param $error
     */
    private function setError($error)
    {
        empty($this->error) && $this->error = $error;
    }

    /**
     * 是否存在错误
     * @return bool
     */
    private function hasError()
    {
        return !empty($this->error);
    }

    /**
     * 获取错误信息
     * @return string
     */
    public function getError()
    {
        return $this->error;
    }

}
