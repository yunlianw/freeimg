# 🖼️ FreeImg / 自由图床

<p align="center">
  <strong>轻量级自建图床系统 · PHP + MySQL · 多云存储 · 完整安全机制</strong>
</p>

<p align="center">
  <img src="https://img.shields.io/badge/PHP-8.0%2B-777BB4?logo=php" alt="PHP">
  <img src="https://img.shields.io/badge/MySQL-5.7%2B-4479A1?logo=mysql" alt="MySQL">
  <img src="https://img.shields.io/badge/License-MIT-green" alt="License">
  <img src="https://img.shields.io/badge/Composer-零依赖-success" alt="Zero Dependency">
</p>

---

## ✨ 功能特性

### 📤 上传 & 处理
- 拖拽 / 粘贴 / 批量上传（多文件并发）
- 浏览器 canvas 预压缩
- 服务端 GD 二次压缩（6 档：原图/高清/均衡/省流/极限/自定义）
- SHA256 去重（同图秒传）
- 水印：文字 + 图片双类型
- 重命名规则：4 种 / 目录规则：6 种

### ☁️ 存储
- **本地存储**（默认，多硬盘挂载点）
- **S3 兼容**（AWS S3 / Cloudflare R2 / Backblaze B2 / Wasabi / MinIO）
- **SFTP**（ed25519 密钥 + 密码双认证）
- **阿里云 OSS**（v1 签名）
- **腾讯云 COS**（XML API · q-sign-algorithm=sha1 签名）
- **华为云 OBS**（原生 v2 签名 · HMAC-SHA1）
- 手动选存储 + 自动 fallback + 容量统计 + 优先级排序

### 🔐 安全
- 2FA 两步验证（Google / 微软 / 1Password 兼容）
- 登录失败锁定（5 次失败锁 15 分钟，可配置）
- 密码强度强制（10 位+ 含大小写+数字+符号）+ 历史防重用（最近 5 个）
- DB-backed 会话（滑动过期，多端登录可配置）
- 登录日志（30 天自动清理）

### 🖼️ 资源管理
- 文件夹（树形分类）+ 虚拟相册（picker 多选批量）
- 分享链接（token + 有效期 + 访问密码 + 公开页）
- 回收站（30 天软删）
- 存储扫描清理（dry-run + 二次确认，防误删）

### 🔌 API
- REST API（PicGo / ShareX / Typora 兼容）
- 差异化压缩：每个 API Key 可绑定独立压缩预设
- 完整文档：[`docs/API.md`](docs/API.md)

---

## 🚀 部署教程（宝塔）

### 1. 环境要求

| 项目 | 要求 |
|:---|:---|
| PHP | 8.0+（推荐 8.2+），扩展：`pdo_mysql` / `gd` / `mbstring` / `fileinfo` / `curl` |
| MySQL | 5.7+（推荐 8.0+） |
| Web 服务器 | Nginx（推荐） / Apache |
| 内存 | 256 MB+ |

### 2. 上传代码

把 `freeimg/` 目录上传到服务器：

```bash
scp -r freeimg/ root@your-server:/www/wwwroot/
```

最终目录：
```
/www/wwwroot/freeimg/
```

### 3. 宝塔 5 步安装

> ⚠️ **关键**：先在「项目根」跑完 `/install/`，再改网站根目录为 `public/`。顺序反了会 404。

| 步骤 | 操作 |
|:---:|:---|
| ① | 宝塔 → 网站 → 添加站点，**网站根目录先保持默认** `/www/wwwroot/freeimg`（不要改 `public/`），选 PHP 8.0+ |
| ② | 宝塔 → 数据库 → 新建数据库 + 用户（记录下密码） |
| ③ | **浏览器访问** `http://你的域名/install/` → 4 步向导：环境检测 → 填 DB → 创建管理员 → 完成 |
| ④ | 宝塔 → 网站 → 设置 → **网站目录改为** `/www/wwwroot/freeimg/public` |
| ⑤ | 宝塔 → 网站 → 设置 → 配置文件 → 粘贴下方「🔀 伪静态规则」的 Nginx 段（Apache 跳过） |

### 4. 权限

```bash
chown -R www:www /www/wwwroot/freeimg
chmod -R 755 /www/wwwroot/freeimg
chmod -R 777 /www/wwwroot/freeimg/storage
chmod -R 777 /www/wwwroot/freeimg/public/storage
chmod 777 /www/wwwroot/freeimg/config
```

### 5. 安全收尾

```bash
# 1. 删除安装目录（必须）
rm -rf /www/wwwroot/freeimg/install/

# 2. 收紧配置权限
chmod 600 /www/wwwroot/freeimg/config/config.php

# 3. 第一次登录后立即：改默认密码 → 开 2FA → 配 Host 白名单（多域名场景）
```

### 6. 快速确认

```bash
curl -I http://img.yourdomain.com/
# 应返回 200，Content-Type: text/html
# 如果 404 → 网站根目录配错了
```

---

## 🔀 伪静态规则

> **核心原则**：网站根目录 → `freeimg/public/`，**所有规则都不写 alias**，依赖 `try_files` 走 nginx root 直读或符号链接。

### Nginx

把下面这段粘贴到宝塔「网站 → 配置文件」（`server { }` 内）：

```nginx
# 拒绝访问敏感目录（install/ 不 deny，安装完成后手动删除）
location ~ ^/(config|database|app|views|assets-src|storage|public/storage)/ {
    deny all;
    return 403;
}
location ~ \.(lock|sql|md|env|gitignore|bak|backup|log)$ {
    deny all;
    return 403;
}

# 伪静态
location / {
    try_files $uri $uri/ /index.php?$query_string;
}

# 静态资源缓存
location ~* \.(jpg|jpeg|png|gif|webp|ico|css|js|svg|woff|woff2)$ {
    expires 30d;
    access_log off;
    add_header Cache-Control "public, max-age=2592000";
}

# PHP
location ~ \.php$ {
    include fastcgi_params;
    fastcgi_pass unix:/tmp/php-cgi-83.sock;  # 改成实际 PHP-FPM sock 路径
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    fastcgi_read_timeout 300;
}

# 上传文件大小
client_max_body_size 20M;
```

### Apache

`.htaccess` 已随项目放在 `public/.htaccess`，**无需额外配置**。如果用 vhost 配置参考：

```apache
<VirtualHost *:80>
    ServerName img.yourdomain.com
    DocumentRoot /www/wwwroot/freeimg/public
    <Directory /www/wwwroot/freeimg/public>
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    RewriteEngine On
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^(.*)$ index.php [QSA,L]
    php_value upload_max_filesize 20M
    php_value post_max_size 20M
</VirtualHost>
```

### 为什么不写 alias？

- **path=public**（默认）：图片物理在 `public/img/`，nginx root 直读，零配置
- **path=storage/images**：在 `public/` 建软链 `ln -s ../storage/images/img img`，`try_files` 自动跟随
- **不要用 alias**：路径写死，换项目就 404

---

## 📁 项目结构

```
freeimg/
├── 📁 public/                          # Web 入口（nginx root → 这里）
│   ├── 📄 index.php                    # 单入口
│   ├── 📁 assets/                      # CSS/JS
│   ├── 📁 storage/                     # 运行时图片
│   │   ├── 📁 images/                  # 上传图片
│   │   └── 📁 watermark/               # 水印 logo
│   ├── 📁 img/                         # 历史图片
│   └── 📄 .htaccess                    # Apache 伪静态
├── 📁 app/
│   ├── 📁 Core/                        # Db/Request/Response/Router/View
│   ├── 📁 Controllers/                 # 控制器（18 个）
│   ├── 📁 Services/                    # Auth/Upload/Totp/Password/Session
│   ├── 📁 Repositories/                # 数据访问层
│   ├── 📁 Drivers/                     # Local/S3/SFTP/OSS/COS/OBS
│   ├── 📁 Processors/                  # 图片处理器
│   ├── 📁 Middleware/                  # Auth/Admin
│   ├── 📁 Helpers/                     # 全局 helper
│   └── 📁 Config/
├── 📁 views/                           # PHP 模板
├── 📁 config/
│   ├── 📄 config.example.php           # 模板
│   └── 📄 routes.php
├── 📁 install/                         # 安装向导（部署后删除）
├── 📁 docs/                            # 文档
├── 📄 .nginx.conf                      # Nginx 伪静态参考
├── 📄 README.md
├── 📄 CHANGELOG.md
└── 📄 LICENSE
```

---

## ⚙️ 配置说明

所有配置通过后台 → 设置 页面修改，存储在 `settings` 表。**不要手工编辑 `config/config.php`**。

| 分类 | 字段 |
|:---|:---|
| 基础设置 | 站点名称、URL、时区、Host 白名单 |
| 上传设置 | 最大文件大小、允许类型、URL 路径前缀、默认压缩档 |
| 水印设置 | 文字/图片双类型，9 宫格位置 |
| 存储设置 | 多存储驱动（local/S3/SFTP/OSS/COS/OBS）|
| 压缩配置 | 内置 5 档 + 自定义，每个 API Key 可独立绑定 |
| 安全策略 | 会话超时、登录失败锁定、密码最小长度、2FA 颁发者 |

---

## 🔌 API 快速示例

完整文档：[`docs/API.md`](docs/API.md)

```bash
# 上传图片
curl -X POST https://img.yourdomain.com/api/v1/upload \
  -H "X-API-Key: fk_xxxxxxxxxxxx" \
  -H "X-API-Secret: sk_xxxxxxxxxxxx" \
  -F "image=@/path/to/photo.jpg" \
  -F "compression=balanced" \
  -F "folder_id=5"

# 响应
{
  "success": true,
  "image": {
    "id": 42,
    "url": "https://img.yourdomain.com/img/AbCdEf.jpg",
    "name": "photo.jpg",
    "size": 102400,
    "width": 1920,
    "height": 1080
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
          "X-API-Key": "fk_xxx",
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

### 备份

```bash
# 数据库（每日）
mysqldump -u root -p freeimg > /backup/freeimg_$(date +%Y%m%d).sql

# 上传文件（每周）
tar czf /backup/freeimg_files_$(date +%Y%m%d).tar.gz \
  /www/wwwroot/freeimg/public/storage/

# 配置
cp /www/wwwroot/freeimg/config/config.php /backup/
```

### 升级

```bash
# 1. 备份
mysqldump -u root -p freeimg > /backup/before_upgrade_$(date +%Y%m%d).sql
cp -r /www/wwwroot/freeimg /backup/freeimg.bak/

# 2. 解压新版（保留 config/ 和 storage/）
cd /www/wwwroot
tar xzf freeimg-v1.x.x.tar.gz
cp -rn freeimg.bak/freeimg/config/* freeimg/config/
cp -rn freeimg.bak/freeimg/public/storage/* freeimg/public/storage/

# 3. 老库升级：手动执行 `php install/upgrade.php` 补种新设置（全新安装会自动执行，可跳过）
```

---

## ❓ 常见问题

**Q: 装完后访问首页 404？**
nginx `root` 必须指向 `public/`，**不是项目根**。`root /www/wwwroot/freeimg/public;` ✅

**Q: 上传失败 "Class finfo not found"？**
启用 PHP `fileinfo` 扩展。`php.ini` 取消 `;extension=fileinfo` 注释，重启 PHP-FPM。

**Q: 2FA 启用了但手机丢了？**
登录页用**备份码**。全丢了就数据库直改：
```sql
UPDATE users SET totp_enabled=0, totp_secret=NULL, totp_backup_codes=NULL WHERE id=1;
```

**Q: 想加 CDN？**
把 `https://img.yourdomain.com/img/` 替换为 CDN 域名即可。nginx 自动回源。支持的 CDN：Cloudflare / 七牛 / 又拍 / 阿里云 OSS / 腾讯云 COS。

**Q: 物理路径有两个 img/？**
`public/img/`（历史图片）和 `public/storage/images/`（代码上传目标）。两个结构不同，代码自动扫描两个根目录比对 DB。建议在设置 → 存储 把图片统一迁移到一个目录。

---

## 🗺️ 路线图

- [x] **v1.1** - API Key 优化 + 域名动态化
- [x] **v1.3** - 多端登录 + 端到端默认值对齐
- [ ] **v1.4** - CDN 集成 + 智能缓存
- [ ] **v2.0** - OAuth 登录 + 插件系统

完整更新日志：[`CHANGELOG.md`](CHANGELOG.md)

---

## 🙏 致谢

### 主要参考
- **[roim-picx](https://github.com/roimdev/roim-picx)** — UI 设计 + 浏览器预压缩
- **[Lsky Pro](https://github.com/lsky-org/lsky-pro)** — 国内图床参考
- **[Chevereto](https://chevereto.com/)** — 商业图床 UI 参考

### 开发协作
- 🐮 **FreeImg 团队**（主开发 + 架构）
- 🦞 **龙虾二号**（DeepSeek 代码审查）

---

## ⚖️ 免责声明

本项目仅供学习交流和个人使用，请勿用于任何违法违规用途。
使用者应遵守所在地区的法律法规，自行承担使用本项目所产生的一切后果。
作者不对本项目的任何使用方式及结果承担责任。

---

## 📄 License

[MIT](LICENSE) — 自由使用、修改、分发、商用，唯一要求：保留版权声明。
