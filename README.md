# 🖼️ FreeImg / 自由图床

<p align="center">
  <strong>🖼️ 轻量级自建图床系统 · PHP + MySQL · 多云存储 · 完整安全机制</strong>
</p>

<p align="center">
  <a href="#-功能特性">✨ 功能特性</a> •
  <a href="#-技术架构">🏗️ 技术架构</a> •
  <a href="#-部署教程">🚀 部署教程</a> •
  <a href="#-配置说明">⚙️ 配置说明</a> •
  <a href="#-API-文档">🔌 API 文档</a> •
  <a href="#-更新日志">📋 更新日志</a> •
  <a href="#-致谢">🙏 致谢</a> •
  <a href="#-license">📄 License</a>
</p>

<p align="center">
  <img src="https://img.shields.io/badge/PHP-8.0%2B-777BB4?logo=php" alt="PHP">
  <img src="https://img.shields.io/badge/MySQL-5.7%2B-4479A1?logo=mysql" alt="MySQL">
  <img src="https://img.shields.io/badge/License-MIT-green" alt="License">
  <img src="https://img.shields.io/badge/Composer-零依赖-success" alt="Zero Dependency">
</p>

---

## 💡 为什么选择 FreeImg？

| 特性 | 说明 |
|:---:|:---|
| 💾 **零依赖** | 纯 PHP 标准库实现，无需 Composer / Node.js / 任何额外运行时 |
| 🏠 **完全自托管** | 数据 100% 在自己服务器，第三方无法删除 |
| ☁️ **多云存储** | 本地 + S3/R2/B2/OBS/SFTP 一键切换 |
| 🔒 **完整安全** | 2FA + 登录锁定 + 密码策略 + 会话管理 + 登录日志 |
| 🧹 **存储自检** | dry-run 预览 + 二次确认，避免误删生产数据 |
| 🚀 **一键安装** | 4 步向导，环境检测 → DB 配置 → 初始化 → 创建管理员 |
| 🔌 **PicGo 兼容** | REST API 完全兼容 PicGo / ShareX / Typora |
| 📦 **超轻量** | 17M 压缩包，144 个文件，纯 PHP 8.0+ |

## ✨ 功能特性

### 📤 上传 & 处理
- [√] 🖱️ 拖拽 / 粘贴 / 批量上传（多文件并发）
- [√] 🧠 浏览器 canvas 预压缩（roim-picx 同款体验）
- [√] 🔧 服务端 GD 二次压缩（6 档：原图/高清/均衡/省流/极限/自定义）
- [√] 🚫 只存最终图（节省磁盘）
- [√] 🔍 SHA256 去重（同图秒传）
- [√] 💧 水印：文字 + 图片双类型（图片优先）
- [√] 📁 重命名规则：4 种（短名/时间戳/原文件名/自定义占位符）
- [√] 📅 目录规则：6 种（无/年/月/日/ymd/自定义）

### ☁️ 存储
- [√] 💾 本地存储（默认）
- [√] 🌐 S3 兼容（AWS S3 / Cloudflare R2 / Backblaze B2 / 华为云 OBS / Wasabi / MinIO）
- [√] 🔐 SFTP（ed25519 密钥 + 密码双认证）
- [√] 🚧 OSS / COS（接口预留，签名实现中）

### 🔐 安全
- [√] 🛡️ 2FA 两步验证（Google Authenticator / 微软 Authenticator / 1Password）
- [√] 🔒 登录失败锁定（5 次失败锁 15 分钟，可配置）
- [√] 🔑 密码强度强制（10 位+ 含大小写+数字+符号）
- [√] 📚 密码历史防重用（最近 5 个）
- [√] 🕐 DB-backed 会话（滑动过期，强制下线）
- [√] 📋 登录日志（30 天自动清理）
- [√] ⚙️ 安全策略（仅管理员可配）

### 🖼️ 资源管理
- [√] 📁 文件夹（树形分类）
- [√] 📚 虚拟相册（picker 多选批量添加，二级目录浏览）
- [√] 🔗 分享链接（token + 有效期 + 访问密码 + 公开页）
- [√] 🗑️ 回收站（30 天软删）
- [√] 🧹 存储扫描清理（dry-run + 二次确认，防误删）

### 💾 多存储管理（v1.1+）
- [√] 🖥️ **多 Local 存储**（多个硬盘挂载点）
- [√] ☁️ **多云存储**（Local / S3 / R2 / OBS / SFTP）
- [√] 🎯 **手动选存储**（上传页下拉，单存储时自动隐藏）
- [√] 🔄 **自动 fallback**（A 满 → B → C，按优先级）
- [√] 📊 **容量统计**（自动累加 + 80% 阈值）
- [√] 🚫 **可见性控制**（隐藏存储作后台备份用，不显示给用户）
- [√] ⚖️ **优先级排序**（数字越大越优先）

### 🔌 API
- [√] 🗝️ REST API（PicGo / ShareX / Typora 兼容）
- [√] 🎯 差异化压缩：每个 API Key 可绑定独立压缩预设
- [√] 📊 完整文档（`docs/API.md`，227 行）

---

## 🏗️ 技术架构

### 架构概览

```
┌──────────────────────────────────────────────┐
│              用户浏览器                         │
│  (Vue3 + Canvas 预压缩 + 拖拽上传)             │
└──────────────────┬───────────────────────────┘
                   │ HTTPS
┌──────────────────▼───────────────────────────┐
│            Nginx (反向代理)                    │
│  · 静态资源直接服务（30 天缓存）                │
│  · 动态请求转发 PHP-FPM                       │
│  · /img/ alias → public/img/ 历史图片         │
└──────────────────┬───────────────────────────┘
                   │
┌──────────────────▼───────────────────────────┐
│          PHP-FPM (8.0+)                        │
│  · 单入口 public/index.php                     │
│  · 路由分发 Router                            │
│  · 中间件：Auth/Admin                         │
└──────────────────┬───────────────────────────┘
                   │
        ┌──────────┼──────────┐
        │          │          │
┌───────▼───┐ ┌────▼────┐ ┌──▼────────┐
│ Upload    │ │ Storage │ │  Auth     │
│ Service   │ │ Drivers │ │  Service  │
│  ·压缩    │ │ Local   │ │  ·2FA     │
│  ·水印    │ │ S3      │ │  ·锁定    │
│  ·去重    │ │ SFTP    │ │  ·会话    │
└─────┬─────┘ └────┬────┘ └────┬──────┘
      │            │           │
      └────────────┼───────────┘
                   │
┌──────────────────▼───────────────────────────┐
│       MySQL 5.7+ (PDO 命名占位符)              │
│  ·users ·images ·folders ·storages            │
│  ·api_keys ·albums ·album_images              │
│  ·upload_logs ·login_logs ·user_sessions     │
│  ·settings                                   │
└──────────────────────────────────────────────┘
```

### 后端技术栈

| 技术 | 版本 | 说明 |
|:---|:---:|:---|
| **PHP** | 8.0+ | 现代 PHP，无 Composer 依赖 |
| **MySQL** | 5.7+ | 关系型数据库（PDO 命名占位符防注入） |
| **GD** | bundled | 图片处理引擎（PNG/JPG/GIF/WebP/BMP） |
| **cURL** | bundled | HTTP 客户端（S3/SFTP 签名请求） |
| **OpenSSL** | bundled | 加密（AES-256-GCM + bcrypt + TOTP） |

### 前端技术栈

| 技术 | 说明 |
|:---|:---|
| **原生 JavaScript** | 无框架依赖，IE11+ 兼容 |
| **HTML5 Canvas** | 浏览器端预压缩 |
| **CSS3** | 现代化 UI（响应式 + 暗色支持） |
| **无构建工具** | 修改即生效，无需 npm/webpack |

### 项目结构

```
freeimg/
├── 📁 public/                          # Web 入口（nginx root → 这里）
│   ├── 📄 index.php                    # 单入口
│   ├── 📁 assets/                      # CSS/JS
│   │   ├── 📄 style.css                # 主样式
│   │   ├── 📄 upload.js                # 上传逻辑
│   │   ├── 📄 settings-scan.js         # 存储扫描
│   │   └── 📁 images.js
│   ├── 📁 storage/                     # 运行时图片
│   │   ├── 📁 images/                  # 上传图片
│   │   └── 📁 watermark/               # 水印 logo
│   └── 📁 img/                         # 历史图片（nginx alias）
├── 📁 app/                             # 应用核心
│   ├── 📁 Core/                        # 框架（Db/Request/Response/Router/View）
│   ├── 📁 Controllers/                 # 控制器（17 个）
│   ├── 📁 Services/                    # 服务（Auth/Upload/Totp/Password/Session）
│   ├── 📁 Repositories/                # 数据访问层（5 个）
│   ├── 📁 Drivers/                     # 存储驱动（Local/S3/SFTP/OSS/COS）
│   ├── 📁 Processors/                  # 图片处理器（GD/ImageMagick）
│   ├── 📁 Middleware/                  # 中间件（Auth/Admin）
│   ├── 📁 Helpers/                     # 全局 helper
│   └── 📁 Config/                      # 应用配置
├── 📁 views/                           # PHP 模板
│   ├── 📁 layouts/                     # 布局
│   ├── 📁 auth/                        # 登录/2FA
│   ├── 📁 security/                    # 安全中心
│   ├── 📁 settings/                    # 设置
│   ├── 📁 upload/                      # 上传
│   ├── 📁 albums/                      # 相册
│   ├── 📁 images/                      # 图片管理
│   └── 📁 share/                       # 公开分享
├── 📁 config/
│   ├── 📄 config.example.php           # 模板（不要提交实际 config.php）
│   └── 📄 routes.php                   # 路由表
├── 📁 database/migrations/             # 数据库迁移 SQL
├── 📁 install/                         # 安装向导（部署后删除）
├── 📁 docs/                            # 文档
├── 📄 README.md                        # 本文件
├── 📄 CHANGELOG.md                     # 更新日志
├── 📄 LICENSE                          # MIT 协议
└── 📄 .gitignore
```

### 数据流：上传

```
用户拖拽文件
   ↓
浏览器 Canvas 预压缩（节省 60-80% 流量）
   ↓
FormData → POST /upload
   ↓
UploadController.handle()
   ↓
UploadService.upload()
   ├─ SHA256 查重
   ├─ GdProcessor.compress() + 水印
   ├─ StorageDriver.put() → 本地/S3/SFTP
   └─ ImageRepository.create() → DB
   ↓
返回 {success: true, image: {url, id, ...}}
```

### 数据流：登录

```
POST /login
   ↓
AuthController.login()
   ├─ LoginSecurityService.findUser()
   ├─ isLocked() → 已锁？拒绝
   ├─ PasswordService.verify() (bcrypt)
   ├─ totp_enabled=1? → 跳 /login/2fa
   └─ completeLogin()
       ├─ SessionService.create() → DB
       ├─ LoginSecurityService.recordSuccess()
       └─ session_regenerate_id() 防 fixation
   ↓
GET /dashboard → AuthMiddleware.check()
```

---

## 🚀 部署教程

### 📋 环境要求

| 项目 | 最低 | 推荐 |
|:---|:---:|:---:|
| **PHP** | 8.0 | 8.2+ |
| **MySQL** | 5.7 | 8.0+ |
| **Web 服务器** | Apache / Nginx | Nginx + PHP-FPM |
| **PHP 扩展** | `pdo_mysql`, `gd`, `mbstring`, `fileinfo`, `curl` | + `imagick`（大图） |
| **内存** | 256 MB | 512 MB+ |
| **磁盘** | 500 MB | 视图片量 |

### 1️⃣ 上传代码

将 `freeimg/` 目录上传到 Web 服务器，例如：
```bash
scp -r freeimg/ root@your-server:/www/wwwroot/
```

最终目录：
```
/www/wwwroot/freeimg/
```

### 2️⃣ 设置目录权限

```bash
cd /www/wwwroot/freeimg
chmod -R 755 .
chown -R www:www .
chmod -R 777 public/storage/
chmod -R 777 storage/
```

### 3️⃣ Web 服务器配置

> ⚠️ **关键**：Nginx/Apache 的**网站根目录**必须指向 `freeimg/public/`，**不是 `freeimg/`**！这是最容易出错的地方。
>
> 错误示例：`root /www/wwwroot/freeimg;` → 404，因为 `public/index.php` 是入口
> 正确示例：`root /www/wwwroot/freeimg/public;`

#### Nginx（推荐）

**完整配置**（复制粘贴可用，注意改 `server_name` 和 `fastcgi_pass`）：

```nginx
server {
    listen 80;
    server_name img.yourdomain.com;  # 改成你的域名
    root /www/wwwroot/freeimg/public; # 网站根目录（必须是 public/ 子目录）
    index index.php;

    # === SSL（生产环境必加，去掉 # 启用）===
    # listen 443 ssl http2;
    # ssl_certificate     /www/server/panel/vhost/cert/img.yourdomain.com/fullchain.pem;
    # ssl_certificate_key /www/server/panel/vhost/cert/img.yourdomain.com/privkey.pem;

    # === 拒绝访问敏感目录（必须）===
    # install/ 不 deny，安装完成后必须手动删除（见下方"安全收尾"）
    location ~ ^/(config|database|app|views|assets-src|storage|public/storage)/ {
        deny all;
        return 403;
    }
    location ~ \.(lock|sql|md|env|gitignore|bak|backup|log)$ {
        deny all;
        return 403;
    }

    # === 伪静态（必须，否则非 index.php 路径全 404）===
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # === 静态资源缓存（图片走 nginx 直接服务，不走 PHP）===
    location ~* \.(jpg|jpeg|png|gif|webp|ico|css|js|svg|woff|woff2)$ {
        expires 30d;
        access_log off;
        add_header Cache-Control "public, max-age=2592000";
    }

    # === 历史图片兼容（如果之前有图在 public/img/）===
    location /img/ {
        alias /www/wwwroot/freeimg/public/img/;
        expires 30d;
    }

    # === 旧版 /uploads/ 兼容（如果用 symlink 失败，可改用 alias）===
    location /uploads/ {
        alias /www/wwwroot/freeimg/storage/images/;
        expires 30d;
    }

    # === PHP 处理 ===
    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/tmp/php-cgi-83.sock;  # ⚠️ 改成你实际的 PHP-FPM sock 路径
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_read_timeout 300;  # 大图上传超时
    }

    # === 上传文件大小 ===
    client_max_body_size 20M;  # 改为你想要的最大上传
}
```

**宝塔面板**（推荐新手）：
1. 宝塔 → 网站 → 添加站点
2. **PHP 版本**：选 8.0 或更高
3. **网站根目录**：⚠️ **`/www/wwwroot/freeimg/public`**（不是 freeimg 本身）
4. 添加完成后：宝塔 → 网站 → 配置文件 → 把上面的伪静态部分（`location /` 等）粘贴进去
5. 重启 Nginx

**快速确认**：
```bash
# 网站根目录正确性测试
curl -I http://img.yourdomain.com/
# 应返回 200，Content-Type: text/html
# 如果 404 → 网站根目录配错了
```

#### Apache

```apache
<VirtualHost *:80>
    ServerName img.yourdomain.com
    DocumentRoot /www/wwwroot/freeimg/public

    <Directory /www/wwwroot/freeimg/public>
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    # 伪静态
    RewriteEngine On
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^(.*)$ index.php [QSA,L]

    # 拒绝敏感目录
    <DirectoryMatch "^/www/wwwroot/freeimg/(config|database|app|views|assets-src|install|storage|public/storage)">
        Require all denied
    </DirectoryMatch>

    # 上传大小
    php_value upload_max_filesize 20M
    php_value post_max_size 20M
</VirtualHost>
```

**宝塔 Apache**：
1. 宝塔 → 网站 → 添加站点
2. **网站根目录**：`/www/wwwroot/freeimg/public`
3. 伪静态：宝塔已自动写入 `.htaccess`（项目自带），无需额外操作

### 4️⃣ 运行安装向导

**检查清单**（重要！按顺序做完才能进安装页）：

```bash
# ✅ 1. 网站根目录已设置为 /www/wwwroot/freeimg/public
# ✅ 2. 伪静态已配置（location / { try_files ... }）
# ✅ 3. PHP-FPM 用户（www）有写权限
chown -R www:www /www/wwwroot/freeimg
# ✅ 4. storage 目录可写
chmod -R 777 /www/wwwroot/freeimg/storage/
chmod -R 777 /www/wwwroot/freeimg/public/storage/
# ✅ 5. config/ 目录可写（安装时写 config.php）
chmod 777 /www/wwwroot/freeimg/config/
```

**打开浏览器**：
```
http://img.yourdomain.com/install/
```

> ⚠️ **注意**：是 `/install/`（项目根），**不是 `/public/install/`**。如果 404 说明网站根目录配错了。

按 4 步提示：

浏览器打开：
```
http://img.yourdomain.com/install/
```

按 4 步提示：

| 步骤 | 名称 | 操作 |
|:---:|:---|:---|
| 1️⃣ | 环境检测 | 自动检查 PHP 版本、扩展、目录权限 |
| 2️⃣ | 数据库配置 | 填 DB 主机/库名/用户/密码（自动建库） |
| 3️⃣ | 初始化 | 自动建表（11 张）+ 创建管理员账号 |
| 4️⃣ | 完成 | 生成 `config/config.php` 和 `install.lock` |

### 5️⃣ 安全收尾

```bash
# 删除安装目录（必须）
rm -rf /www/wwwroot/freeimg/install/

# 收紧配置权限
chmod 600 /www/wwwroot/freeimg/config/config.php

# 备份配置（异地）
cp /www/wwwroot/freeimg/config/config.php /backup/

# 第一次登录后立即：
# 1. 修改默认密码
# 2. 开启 2FA（保存备份码！）
# 3. 设置安全策略
```

---

## ⚙️ 配置说明

所有配置通过后台 → 设置 页面修改，存储在 `settings` 表。**不要手工编辑 `config/config.php`**。

### 基础设置
- 站点名称、URL、时区

### 上传设置
- 最大文件大小（默认 10MB）
- 允许的文件类型（jpg/jpeg/png/gif/webp/bmp）
- URL 路径前缀（默认 `img`）
- 默认压缩档位

### 水印设置
- 文字水印：字体/大小/颜色/透明度/角度/位置/边距
- 图片水印：大小/透明度/位置/边距
- 优先级：图片 > 文字

### 存储设置
- 多存储驱动配置（local/S3/SFTP）
- 后台可配 + 连接测试

### 压缩配置
- 内置 5 档 + 自定义
- 每个 API Key 可绑定独立档位

### 安全策略（仅管理员）
- 会话超时（1 小时 ~ 12 个月）
- 登录失败锁定（次数/时长）
- 密码最小长度 + 历史防重用
- 2FA 颁发者名称

---

## 🔌 API 文档

完整文档：[`docs/API.md`](docs/API.md)

### 快速示例

```bash
# 1. 在后台创建 API Key（🔑 API → 创建）
#    会得到 access_key 和 secret_key（secret 仅显示一次）

# 2. 上传图片
curl -X POST https://img.yourdomain.com/api/v1/upload \
  -H "X-API-Key: ak_xxxxxxxxxxxx" \
  -H "X-API-Secret: sk_xxxxxxxxxxxx" \
  -F "image=@/path/to/photo.jpg" \
  -F "quality=balanced" \
  -F "album_id=5"        # 可选：归入相册
  -F "expires_at=2027-01-01"  # 可选：过期时间

# 响应
{
  "success": true,
  "duplicate": false,
  "image": {
    "id": 42,
    "uuid": "f05ca042-566a-4474-a806-a87971245078",
    "url": "https://img.yourdomain.com/img/AbCdEf.jpg",
    "name": "photo.jpg",
    "size": 102400,
    "width": 1920,
    "height": 1080,
    "album_id": 5
  }
}
```

### PicGo 配置

```json
{
  "picBed": {
    "current": "freeimg",
    "uploads": {
      "freeimg": {
        "name": "FreeImg",
        "url": "https://img.yourdomain.com",
        "headers": {
          "X-API-Key": "ak_xxx",
          "X-API-Secret": "sk_xxx"
        },
        "formDataName": "image",
        "jsonPath": "image.url"
      }
    }
  }
}
```

---

## 🔧 维护

### 清理孤立文件

后台 → 设置 → 🧹 存储扫描与清理：

| 操作 | 说明 | 保护 |
|:---|:---|:---|
| 🔍 扫描 | 列出磁盘 vs DB 差异 | 无副作用 |
| 🗑️ 清理孤儿文件 | 磁盘有但 DB 无（**dry-run 必看** + 输入"我确认删除"） | 二次确认 |
| ⚠️ 清理孤儿记录 | DB 有但磁盘无（移入回收站，可恢复） | 软删 |

### 备份

```bash
# 1. 数据库（每日）
mysqldump -u root -p freeimg > /backup/freeimg_$(date +%Y%m%d).sql

# 2. 上传文件（每周）
tar czf /backup/freeimg_files_$(date +%Y%m%d).tar.gz \
  /www/wwwroot/freeimg/public/storage/

# 3. 配置（改后即备份）
cp /www/wwwroot/freeimg/config/config.php /backup/config.bak
```

### 升级

```bash
# 1. 备份（必做）
mysqldump -u root -p freeimg > /backup/before_upgrade_$(date +%Y%m%d).sql
cp -r /www/wwwroot/freeimg /backup/freeimg.bak/

# 2. 解压新版（保留 config/ 和 storage/）
cd /www/wwwroot
tar xzf freeimg-v1.1.0.tar.gz
cp -rn freeimg.bak/freeimg/config/* freeimg/config/
cp -rn freeimg.bak/freeimg/public/storage/* freeimg/public/storage/

# 3. 检查新迁移（如有）
mysql -u root -p freeimg < freeimg/database/migrations/upgrade_v1.1.0.sql

# 4. 清理缓存 + 重启 PHP-FPM（看你的服务器管理方式）
```

---

## ❓ 常见问题

<details>
<summary><b>Q: 装完后访问首页 404？</b></summary>

A: nginx `root` 必须指向 `public/`，**不是项目根**。

```nginx
root /www/wwwroot/freeimg/public;  # ✅
# root /www/wwwroot/freeimg;      # ❌
```
</details>

<details>
<summary><b>Q: 上传失败 "Class finfo not found"？</b></summary>

A: 启用 PHP `fileinfo` 扩展。`php.ini` 取消 `;extension=fileinfo` 注释，重启 PHP-FPM。
</details>

<details>
<summary><b>Q: 2FA 启用了但手机丢了？</b></summary>

A: 登录页使用**备份码**（你保存的 10 个一次性码）。**全丢了**就**数据库直改**：
```sql
UPDATE users SET totp_enabled=0, totp_secret=NULL, totp_backup_codes=NULL WHERE id=1;
```
</details>

<details>
<summary><b>Q: 想加 CDN？</b></summary>

A: 把 `https://img.yourdomain.com/img/` 替换为 CDN 域名即可。nginx 自动回源。

支持的 CDN：Cloudflare / 七牛 / 又拍 / 阿里云 OSS / 腾讯云 COS。
</details>

<details>
<summary><b>Q: 物理路径有两个 img/？</b></summary>

A: `public/img/`（历史图片，nginx alias 直服务）和 `public/storage/images/`（代码上传目标）。两个目录结构不同，代码会自动扫描两个根目录比对 DB。建议在设置 → 存储 把图片统一迁移到一个目录。
</details>

<details>
<summary><b>Q: 想支持 WebP/AVIF 自动转？</b></summary>

A: 当前用 GD，可手动加 `cwebp` 系统命令或升级到 ImageMagick（已预留接口）。AVIF 需要 ImageMagick 7+。
</details>

---

## 🗺️ 路线图

- [ ] **v1.1.0** - OSS 签名 v1→v4 修复 + COS 重写
- [ ] **v1.2.0** - 多用户系统（DB 已预留字段）
- [ ] **v1.3.0** - Webhook 通知 + 邮件通知
- [ ] **v1.4.0** - CDN 集成 + 智能缓存
- [ ] **v2.0.0** - OAuth 登录 + 插件系统

---

## 📋 更新日志

完整日志：[`CHANGELOG.md`](CHANGELOG.md)

**v1.0.0-alpha** (2026-08-29) - 首个公开测试版本
- 上传、压缩、水印、重命名、目录规则 ✅
- 多云存储（Local/S3/SFTP）✅
- 2FA + 登录锁定 + 密码策略 ✅
- 相册 + 分享 + 公开页 ✅
- REST API（PicGo/ShareX 兼容）✅
- 存储扫描清理（dry-run + 二次确认）✅
- 安装向导（4 步）✅

---

## 🙏 致谢

本项目参考了以下开源项目：

### 主要参考
- **[roim-picx](https://github.com/roimdev/roim-picx)** ⭐⭐⭐
  - 优秀的图床 UI 设计理念
  - 浏览端 Canvas 预压缩方案
  - 拖拽 / 粘贴 / 批量上传的交互体验
  - 多目录管理 + 相册管理思路
  - REST API 设计参考

### 技术参考
- **[Chevereto](https://chevereto.com/)** - 商业图床，UI 风格参考
- **[Cloudinary](https://cloudinary.com/)** - 云存储 + CDN 架构
- **[Imgur](https://imgur.com/)** - 用户体验设计
- **[Lsky Pro](https://github.com/lsky-org/lsky-pro)** - 国内图床参考
- **[ZPic](https://github.com/Photographer-luis/ZPic)** - 轻量级图床参考

### 标准与协议
- **[RFC 6238](https://tools.ietf.org/html/rfc6238)** - TOTP 算法标准
- **[RFC 4226](https://tools.ietf.org/html/rfc4226)** - HOTP 算法标准
- **[RFC 4648](https://tools.ietf.org/html/rfc4648)** - Base32 编码标准
- **[Keep a Changelog](https://keepachangelog.com/)** - 更新日志规范
- **[Semantic Versioning](https://semver.org/)** - 语义化版本

### 开发协作
- 🐮 **牛马一号**（主开发者 + 架构设计）
- 🦞 **龙虾二号**（基于 DeepSeek 的代码审查）
- 🎯 **老季**（产品需求 + 测试）

### 工具
- PHP 8.5 / MySQL 5.7 / Nginx / Composer-free
- DeepSeek（龙虾二号审查）
- 宝塔面板（部署管理）

---

## ⚖️ 免责声明

本项目仅供学习交流和个人使用，请勿用于任何违法违规用途。
使用者应遵守所在地区的法律法规，自行承担使用本项目所产生的一切后果。
作者不对本项目的任何使用方式及结果承担责任。

---

## 📄 License

本项目采用 [MIT 协议](LICENSE)。

```
MIT License - 自由使用、修改、分发、商用
唯一要求：保留版权声明
```

---

## 📞 支持

- 📖 **文档**：项目内 `docs/` 目录
- 🐛 **Bug 反馈**：GitHub Issues
- 💬 **讨论交流**：GitHub Discussions
- 🌐 **演示**：https://pic.5276.net

---

<p align="center">
  <strong>Made with ❤️ by 老季 & 牛马一号 🐮</strong><br>
  <sub>Powered by PHP + MySQL · 零 Composer 依赖</sub>
</p>
