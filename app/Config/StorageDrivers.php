<?php
/**
 * 存储驱动定义（后台表单动态渲染 + 帮助教程）
 * 每个驱动：label / icon / desc / fields / tips
 * field: name / label / type(text|password|number|select) / required / placeholder / default / help / options
 */

return [
    'local' => [
        'label'  => '本地存储',
        'icon'   => '💾',
        'desc'   => '图片保存在本机服务器磁盘',
        'fields' => [
            'path' => ['label' => '存储根目录', 'type' => 'text', 'required' => false,
                'placeholder' => '如 storage（默认用项目 storage 目录）',
                'help' => '绝对路径或相对项目根目录的路径，留空使用默认'],
            'url'  => ['label' => '公开访问 URL 前缀', 'type' => 'text', 'required' => false,
                'placeholder' => 'https://yourdomain.com',
                'help' => '图片对外访问的地址前缀，通常就是站点域名'],
        ],
        'tips' => '本地存储开箱即用，无需额外配置，适合单机部署。',
    ],

    'sftp' => [
        'label'  => 'SFTP 远程服务器',
        'icon'   => '🔐',
        'desc'   => '图片传到另一台服务器（走 SSH 加密）',
        'fields' => [
            'host' => ['label' => '服务器地址', 'type' => 'text', 'required' => true,
                'placeholder' => '如 123.45.67.89 或 sftp.example.com'],
            'port' => ['label' => '端口', 'type' => 'number', 'required' => true,
                'default' => '22', 'placeholder' => '22'],
            'username' => ['label' => '用户名', 'type' => 'text', 'required' => true,
                'placeholder' => 'SSH 登录用户名，如 www'],
            'password' => ['label' => '密码', 'type' => 'password', 'required' => false,
                'placeholder' => 'SSH 密码（用密钥认证可留空）',
                'help' => '与私钥二选一；服务器禁止密码登录时留空'],
            'private_key' => ['label' => '私钥文件路径', 'type' => 'text', 'required' => false,
                'placeholder' => '如 /home/www/.ssh/freeimg',
                'help' => '本机私钥绝对路径，同目录必须有 .pub 公钥文件'],
            'private_key_passphrase' => ['label' => '私钥密码（可选）', 'type' => 'password', 'required' => false,
                'placeholder' => '生成私钥时设置的密码，没有就留空'],
            'path' => ['label' => '远程存储目录', 'type' => 'text', 'required' => true,
                'placeholder' => '如 /home/www/uploads',
                'help' => '目标服务器上的绝对路径，需已存在且可写'],
            'public_url' => ['label' => '公开访问 URL 前缀（可选）', 'type' => 'text', 'required' => false,
                'placeholder' => 'https://img.example.com',
                'help' => '目标服务器能直接访问图片时的 URL；留空则图片仅存不展示'],
        ],
        'tips' => '适合把图片存到独立存储服务器/大硬盘机器。首次使用请看下方小白教程 👇',
        'tutorial' => 'sftp',
    ],

    's3' => [
        'label'  => 'S3 兼容存储',
        'icon'   => '☁️',
        'desc'   => 'AWS S3 / Cloudflare R2 / Backblaze / MinIO 等',
        'fields' => [
            'endpoint' => ['label' => 'Endpoint 地址', 'type' => 'text', 'required' => true,
                'placeholder' => '如 https://s3.us-east-1.amazonaws.com',
                'help' => 'R2: https://<accountid>.r2.cloudflarestorage.com'],
            'region' => ['label' => 'Region 区域', 'type' => 'text', 'required' => true,
                'default' => 'auto', 'placeholder' => '如 us-east-1 或 auto'],
            'bucket' => ['label' => 'Bucket 名称', 'type' => 'text', 'required' => true],
            'access_key' => ['label' => 'Access Key', 'type' => 'text', 'required' => true],
            'secret_key' => ['label' => 'Secret Key', 'type' => 'password', 'required' => true],
            'path_style' => ['label' => '地址风格', 'type' => 'select', 'required' => false,
                'default' => '1', 'options' => ['1' => 'Path 风格（bucket/文件）', '0' => '虚拟主机风格（bucket.域名）'],
                'help' => 'MinIO/自建 S3 用 Path 风格；AWS 云服务用虚拟主机风格'],
            'public_url' => ['label' => '公开访问 URL 前缀（可选）', 'type' => 'text', 'required' => false,
                'placeholder' => 'https://cdn.example.com',
                'help' => '一般留空自动生成；自定义 CDN 域名时填写'],
        ],
        'tips' => '一个配置通吃 AWS S3、Cloudflare R2、Backblaze B2、Wasabi、MinIO、华为云 OBS。',
    ],

    'cos' => [
        'label'  => '腾讯云 COS',
        'icon'   => '🐧',
        'desc'   => '腾讯云对象存储（开发中，暂不可用）',
        'fields' => [
            'secret_id' => ['label' => 'SecretId', 'type' => 'text', 'required' => true],
            'secret_key' => ['label' => 'SecretKey', 'type' => 'password', 'required' => true],
            'region' => ['label' => 'Region 区域', 'type' => 'text', 'required' => true,
                'placeholder' => '如 ap-guangzhou'],
            'bucket' => ['label' => 'Bucket 名称', 'type' => 'text', 'required' => true,
                'placeholder' => '如 mybucket-1250000000'],
            'public_url' => ['label' => '公开访问 URL 前缀（可选）', 'type' => 'text', 'required' => false],
        ],
        'tips' => '⚠️ 该驱动正在开发中，暂时无法使用，敬请期待。',
    ],

    'oss' => [
        'label'  => '阿里云 OSS',
        'icon'   => '☁️',
        'desc'   => '阿里云对象存储',
        'fields' => [
            'access_key_id' => ['label' => 'AccessKey ID', 'type' => 'text', 'required' => true],
            'access_key_secret' => ['label' => 'AccessKey Secret', 'type' => 'password', 'required' => true],
            'endpoint' => ['label' => 'Endpoint', 'type' => 'text', 'required' => true,
                'placeholder' => '如 oss-cn-hangzhou.aliyuncs.com'],
            'bucket' => ['label' => 'Bucket 名称', 'type' => 'text', 'required' => true],
            'public_url' => ['label' => '公开访问 URL 前缀（可选）', 'type' => 'text', 'required' => false],
        ],
        'tips' => '阿里云对象存储，按量付费，国内访问快。',
    ],

    'obs' => [
        'label'  => '华为云 OBS',
        'icon'   => '🌐',
        'desc'   => '华为云对象存储（S3 兼容协议）',
        'fields' => [
            'endpoint' => ['label' => 'Endpoint', 'type' => 'text', 'required' => true,
                'placeholder' => '如 obs.cn-north-4.myhuaweicloud.com'],
            'region' => ['label' => 'Region', 'type' => 'text', 'required' => true,
                'placeholder' => '如 cn-north-4'],
            'bucket' => ['label' => 'Bucket 名称', 'type' => 'text', 'required' => true],
            'access_key' => ['label' => 'Access Key', 'type' => 'text', 'required' => true],
            'secret_key' => ['label' => 'Secret Key', 'type' => 'password', 'required' => true],
            'path_style' => ['label' => '地址风格', 'type' => 'select', 'required' => false,
                'default' => '1', 'options' => ['1' => 'Path 风格', '0' => '虚拟主机风格']],
            'public_url' => ['label' => '公开访问 URL 前缀（可选）', 'type' => 'text', 'required' => false],
        ],
        'tips' => '华为云对象存储，走 S3 兼容协议，配置同 S3。',
    ],
];
