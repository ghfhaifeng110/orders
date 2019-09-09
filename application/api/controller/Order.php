<?php

namespace app\api\controller;

use app\api\model\Order as OrderModel;
use app\api\model\Wxapp as WxappModel;
use app\api\model\Cart as CartModel;
use app\api\model\WxappPrepayId as WxappPrepayIdModel;
use app\common\library\wechat\WxPay;

/**
 * 订单控制器
 * Class Order
 * @package app\api\controller
 */
class Order extends Controller
{
    /* @var \app\api\model\User $user */
    private $user;

    /**
     * 构造方法
     * @throws \app\common\exception\BaseException
     * @throws \think\exception\DbException
     */
    public function _initialize()
    {
        parent::_initialize();
        $this->user = $this->getUser();   // 用户信息
    }

    /**
     * 订单确认-立即购买
     * @param int $goods_id 商品id
     * @param int $goods_num 购买数量
     * @param int $goods_sku_id 商品sku_id
     * @param int $delivery 配送方式
     * @param int $coupon_id 优惠券id
     * @param int $shop_id 自提门店id
     * @param string $remark 买家留言
     * @return array
     * @throws \app\common\exception\BaseException
     * @throws \think\exception\DbException
     * @throws \Exception
     */
    public function buyNow(
        $goods_id,
        $goods_num,
        $goods_sku_id,
        $delivery,
        $shop_id = 0,
        $coupon_id = null,
        $remark = ''
    )
    {
        // 商品结算信息
        $model = new OrderModel;
        $order = $model->getBuyNow($this->user, $goods_id, $goods_num, $goods_sku_id, $delivery, $shop_id);
        if (!$this->request->isPost()) {
            return $this->renderSuccess($order);
        }
        if ($model->hasError()) {
            return $this->renderError($model->getError());
        }
        // 创建订单
        if ($model->createOrder($this->user['user_id'], $order, $coupon_id, $remark)) {
            // 发起微信支付
            return $this->renderSuccess([
                'payment' => $this->unifiedorder($model),
                'order_id' => $model['order_id']
            ]);
        }
        $error = $model->getError() ?: '订单创建失败';
        return $this->renderError($error);
    }

    /**
     * 订单确认-购物车结算
     * @param string $cart_ids (支持字符串ID集)
     * @param int $delivery 配送方式
     * @param int $shop_id 自提门店id
     * @param int $coupon_id 优惠券id
     * @param string $remark 买家留言
     * @return array
     * @throws \app\common\exception\BaseException
     * @throws \think\Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     * @throws \Exception
     */
    public function cart(
        $cart_ids,
        $delivery,
        $shop_id = 0,
        $coupon_id = null,
        $remark = ''
    )
    {
        // 商品结算信息
        $Card = new CartModel($this->user);
        $order = $Card->getList($cart_ids, $delivery, $shop_id);
        if (!$this->request->isPost()) {
            return $this->renderSuccess($order);
        }
        // 创建订单
        $model = new OrderModel;
        if ($model->createOrder($this->user['user_id'], $order, $coupon_id, $remark)) {
            // 移出购物车中已下单的商品
            $Card->clearAll($cart_ids);
            // 发起微信支付
            return $this->renderSuccess([
                'payment' => $this->unifiedorder($model),
                'order_id' => $model['order_id']
            ]);
        }
        return $this->renderError($model->getError() ?: '订单创建失败');
    }

    /**
     * 构建微信支付
     * @param $order
     * @return array
     * @throws \app\common\exception\BaseException
     * @throws \think\exception\DbException
     */
    private function unifiedorder($order)
    {
        // 统一下单API
        $wxConfig = WxappModel::getWxappCache();
        $WxPay = new WxPay($wxConfig);
        $payment = $WxPay->unifiedorder($order['order_no'], $this->user['open_id'], $order['pay_price']);
        // 记录prepay_id
        $model = new WxappPrepayIdModel;
        $model->add($payment['prepay_id'], $order['order_id'], $this->user['user_id'], 10);
        return $payment;
    }

}
