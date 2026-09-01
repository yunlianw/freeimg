<?php
/**
 * FreeImg 配置文件
 * 此文件由安装程序自动生成，请勿手工修改（除非你知道自己在做什么）
 *
 * v1.1.9+ 大幅精简：仅保留程序真正使用的字段
 */

return [
    // 应用
    // ⚠️ v1.1.7+ 「主域名」统一在后台「系统设置 → 基础设置」配置（写入 settings.site_url）
    'app' => [
        'timezone'       => 'Asia/Shanghai',
        'debug'          => false,
        'encryption_key' => '__ENC_KEY__',
    ],

    // 数据库
    'database' => [
        'host'     => '__DB_HOST__',
        'port'     => __DB_PORT__,
        'dbname'   => '__DB_NAME__',
        'username' => '__DB_USER__',
        'password' => '__DB_PASS__',
        'charset'  => 'utf8mb4',
    ],

    // Session（cookie_httponly 和 cookie_samesite 由 index.php 硬编码，无需配置）
    'session' => [
        'name'          => 'FREEIMG_SESS',
        'lifetime'      => 7200,
        'cookie_secure' => false,
    ],

    // 上传
    'upload' => [
        'max_size'   => 10 * 1024 * 1024,    // 10MB
        'max_pixels' => 16 * 1024 * 1024,    // 像素炸弹防护：单图最大 16MP（宽×高）
    ],
];
