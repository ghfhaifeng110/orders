<?php

namespace app\common\model;

use think\Model;
use think\Request;
use think\Session;

/**
 * 模型基类
 * Class BaseModel
 * @package app\common\model
 */
class BaseModel extends Model
{
    public static $wxapp_id;
    public static $base_url;

    /**
     * 模型基类初始化
     */
    public static function init()
    {
        parent::init();
        // 获取当前域名
        self::$base_url = base_url();
        // 后期静态绑定wxapp_id
        self::bindWxappId();
    }

    /**
     * 获取当前调用的模块名称
     * 例如：admin, api, store, task
     * @return string|bool
     */
    protected static function getCalledModule()
    {
        if (preg_match('/app\\\(\w+)/', get_called_class(), $class)) {
            return $class[1];
        }
        return false;
    }

    /**
     * 后期静态绑定类名称
     * 用于定义全局查询范围的wxapp_id条件
     * 子类调用方式:
     *   非静态方法:  self::$wxapp_id
     *   静态方法中:  $self = new static();   $self::$wxapp_id
     */
    private static function bindWxappId()
    {
        if ($module = self::getCalledModule()) {
            $callfunc = 'set' . ucfirst($module) . 'WxappId';
            method_exists(new self, $callfunc) && self::$callfunc();
        }
    }

    /**
     * 设置wxapp_id (store模块)
     */
    protected static function setStoreWxappId()
    {
        $session = Session::get('weidan_store');
        self::$wxapp_id = $session['wxapp']['wxapp_id'];
    }

    /**
     * 设置wxapp_id (api模块)
     */
    protected static function setApiWxappId()
    {
        $request = Request::instance();
        self::$wxapp_id = $request->param('wxapp_id');
    }

    /**
     * 定义全局的查询范围
     * @param \think\db\Query $query
     */
    protected function base($query)
    {
        if (self::$wxapp_id > 0) {
            $query->where($query->getTable() . '.wxapp_id', self::$wxapp_id);
        }
    }

}
