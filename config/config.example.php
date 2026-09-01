<?php
/**
 * FreeImg 配置文件
 * 此文件由安装程序自动生成，请勿手工修改（除非你知道自己在做什么）
 */

return [
    // 应用
    // ⚠️ v1.1.7+ 「主域名」统一在后台「系统设置 → 基础设置」配置（写入 settings.site_url）
    // 这里不再需要 url 字段，安装程序也不会再读 __SITE_URL__ 占位符
    'app' => [
        'name'           => 'FreeImg',
        'timezone'       => 'Asia/Shanghai',
        'debug'          => false,
        'encryption_key' => '__ENC_KEY__',

        // === 多域名白名单（v1.1.8+ 可选）===
        // 适用场景：宝塔里多个站点指向同一图床目录
        // 作用：防止客户端伪造 Host 头导致 API 返回恶意域名
        // 留空（默认）：仅信任系统设置里的主域名（settings.site_url）
        // 填写示例：['pic.5276.net', 'img.example.com', 'cdn.example.com']
        // 优先：建议在后台「Host 白名单」直接配置（更方便，v1.1.8.1+）
        // 注意：开启后台「多域名模式」开关后必须填此项
        // 'allowed_hosts' => [],
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
    ],

    // 日志
    'log' => [
        'level'   => 'info',
        'channel' => 'file',
    ],
];