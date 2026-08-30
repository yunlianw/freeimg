<?php
/**
 * FreeImg 配置文件
 * 此文件由安装程序自动生成，请勿手工修改（除非你知道自己在做什么）
 */

return [
    // 应用
    'app' => [
        'name'           => 'FreeImg',
        'url'            => '__SITE_URL__',
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
        'collation'=> 'utf8mb4_general_ci',
    ],

    // Session
    'session' => [
        'name'           => 'FREEIMG_SESS',
        'lifetime'       => 7200,
        'cookie_secure'  => false,
        'cookie_httponly'=> true,
        'cookie_samesite'=> 'Lax',
    ],

    // 上传
    'upload' => [
        'max_size'      => 10 * 1024 * 1024, // 10MB
        'allowed_types' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'],
        'chunk_size'    => 2 * 1024 * 1024,
        'max_pixels'    => 16 * 1024 * 1024, // 像素炸弹防护：单图最大 16MP（宽×高）；128M memory_limit 下 GD 解码安全
    ],

    // 图片处理
    'image' => [
        'driver'          => 'gd', // gd 或 imagick
        'default_quality' => 85,
        'max_width'       => 4096,
        'max_height'      => 4096,
    ],

    // 存储
    'storage' => [
        'default'   => 'local',
        'local_path'=> __DIR__ . '/../storage/images',
        'local_url' => '/uploads',
    ],

    // 日志
    'log' => [
        'level'   => 'info',
        'channel' => 'file',
    ],
];