<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Log;

class SettingKitchenHelper
{
    public static function printStyle($settings = [], $size = null, $key = null): string
    {
        if(!$settings || !is_array($settings)){
            return '';
        }
        $styles = [];

        if ($size !== null && $key !== null && isset($settings[$size][$key])) {
            $setting = $settings[$size][$key];

            // Nếu có font mới thêm font-size
            if (isset($setting['font'])) {
                $styles[] = "font-size: {$setting['font']}px";
            }

            // Nếu visible khác 'true' thì ẩn
            if (($setting['visible'] ?? 'true') !== 'true') {
                $styles[] = "display: none";
            }
        }

        return implode('; ', $styles);
    }
}
