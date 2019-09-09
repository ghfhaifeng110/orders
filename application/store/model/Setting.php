<?php

namespace app\store\model;

use app\common\model\Setting as SettingModel;
use think\Cache;

/**
 * 系统设置模型
 * Class Wxapp
 * @package app\store\model
 */
class Setting extends SettingModel
{
    /**
     * 设置项描述
     * @var array
     */
    private $describe = [
        'store' => '商城设置',
        'trade' => '交易设置',
        'sms' => '短信通知',
        'tplMsg' => '模板消息',
        'storage' => '上传设置',
        'printer' => '小票打印',
        'full_free' => '满额包邮设置',
    ];

    /**
     * 更新系统设置
     * @param $key
     * @param $values
     * @return bool
     * @throws \think\exception\DbException
     */
    public function edit($key, $values)
    {
        $model = self::detail($key) ?: $this;
        // 数据验证
        if (!$this->validValues($key, $values)) {
            return false;
        }
        // 删除系统设置缓存
        Cache::rm('setting_' . self::$wxapp_id);
        return $model->save([
                'key' => $key,
                'describe' => $this->describe[$key],
                'values' => $values,
                'wxapp_id' => self::$wxapp_id,
            ]) !== false;
    }

    /**
     * 数据验证
     * @param $key
     * @param $values
     * @return bool
     */
    private function validValues($key, $values)
    {
        // 验证小票打印机设置
        if ($key === 'printer') {
            return $this->validPrinter($values);
        }
        return true;
    }

    /**
     * 验证小票打印机设置
     * @param $values
     * @return bool
     */
    private function validPrinter($values)
    {
        if ($values['is_open'] == false) {
            return true;
        }
        if (!$values['printer_id']) {
            $this->error = '请选择订单打印机';
            return false;
        }
        if (empty($values['order_status'])) {
            $this->error = '请选择订单打印方式';
            return false;
        }
        return true;
    }

}
