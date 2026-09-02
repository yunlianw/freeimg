# 📋 Changelog

FreeImg 所有版本更新日志。

格式参考 [Keep a Changelog](https://keepachangelog.com/zh-CN/1.1.0/)，
本项目遵循 [语义化版本](https://semver.org/lang/zh-CN/) 规范。

---

## [Unreleased]

---

## [v1.3.9] - 2026-09-02

### 🐛 BUG 修复：双重压缩「取小」+ 上传页默认档读设置

老季反馈「浏览器压缩可能 50KB，后端压缩反而 100KB」+「后台默认压缩档位改了但上传页默认不跟着变」。

**1. 双重压缩「取小」方案**（老季提出，虾二号审查通过）
- 双重压缩模式（browser_upload_mode='double'）：前端先按 QUALITY_PRESETS 压 → 后端再压 → **取字节数最小的那个**
- 核心逻辑：CompressionChain.php 第 207 行 `$cmpSize >= $srcSize` 已有，**不动业务代码，只修隐藏坑**
- **隐藏坑**（虾二号发现）：strip_exif=1 时保留原图分支会强制 q92 重写剥 EXIF，把前端 50KB 变 150KB，「取小」失效
- **修复**（UploadService.php + CompressionChain.php）：
  - 新增 `$inputFromBrowser` 判定：前端已压过图（`skipByBrowser` 或 `original_size !== realSize`）
  - chain 收 `strip_exif=0` → 保留原图分支不再重写剥 EXIF → 真正保留前端小图
  - 真原图走原 strip_exif 逻辑（隐私安全）
- 前端零改动

**2. 上传页默认档读 settings**
- 之前 v1.3.8 browser/double 模式上传页默认**写死 'balanced'**，后台「设置 → 默认压缩档位」改不了
- **修复**（UploadController.php）：读 `settings.default_compression`，白名单 5 档（原图/高清/均衡/省流/极限），兜底 balanced
- view hint 文案同步：「默认档来自后台「设置 → 默认压缩档位」」
- 老季本机 `default_compression=saver` → 现在打开 https://pic.5276.net/upload 默认显示「省流」

**审查**：龙虾二号 2 分钟审查 + 1 分钟方案评估，全部 PASS。

---

## [v1.3.7] - 2026-09-02

### 🐛 BUG 修复：存储扫描彻底修对 + url_path_prefix 支持多级

老季要求「默认 img / 改成 tu / 改成 img/tu 都能扫到所有真实图片，不出现文件对不上」，龙虾二号推荐 C 方案（修扫描器）+ 牛马一号 + 龙虾二号一致同意 prefix 允许多级。本轮落地：

**url_path_prefix 全面支持多级**（8 个文件）：
- 12 处 `preg_replace('/[^a-zA-Z0-9_-]/', ...)` → `'/[^a-zA-Z0-9\/_-]/'`（保留 `/`）
- SettingController 校验增加 `..` 和 `//` 安全守卫（防路径穿越/空段）
- 默认值 fallback `rest/new` → `img`（老季朋友新装走 fallback 错误修复）
- views 提示文案加单级 + 多级各一个示例

**存储扫描逻辑彻底修对**（StorageScanController）：
- 新增 `localBaseDirs()`：从 storages 表读所有 local 驱动的真实 basePath（解密 config）
- scan / cleanup / cleanupRecords 三处都用 `localBaseDirs()`，不再硬编码 `public/{prefix}`
- cleanupRecords 加 is_dir 守卫（虾二号找出的**数据风险**）：防止 storages.path 配错时批量误删 active 记录
- scan 响应 `baseDir`（单数，未定义变量）→ `baseDirs`（数组）
- **跳过 storage/ 目录**：watermark/logo.png 是运行时文件，不应被认成孤儿文件

**附带 bug 修复**：
- upgrade.php v1.3.2 段 `json_decode($row['config'])` 没走 `decrypt_secret`，导致 storages.config（AES-GCM 加密）的 /uploads 后缀修复**对加密配置静默失效**。改为 `json_decode(decrypt_secret(...))`，写回用 `encrypt_secret(...)`，并 require_once functions.php
- Installer.php:126 注释「仅支持单级」→「v1.3.6: 支持多级」

**验证**（生产库 pic_5276_net 实测）：
- DB 19 张图 / 磁盘 20 文件 → 孤儿记录 0 / 孤儿文件 0（修复前是 1 个 watermark logo 误判）
- `url_path_prefix=img` 已种，新装走 seedSettings='img'
- 老库升级：手动跑 `php install/upgrade.php` 安全（解密不抛异常，/uploads 零命中零写入）

**审查**：龙虾二号 2 分钟最终审查全 PASS（4 项修复 + 加密 bug + storage/ 排除）

---

## [v1.3.6] - 2026-09-02

中间过渡版，与 v1.3.7 合并发布（跳过单独 release tag）。

---

## [v1.3.5] - 2026-09-02

### 🐛 BUG 修复：url_path_prefix 默认值与开发环境不一致

老季要求「新装版本跟我一样」，虾二号端到端对比找出：
- 老季本机 `url_path_prefix=img`，新装 placeholder `rest/new`（错的）
- fix：`views/settings/index.php` placeholder `img`，fallback `img`
- 新增 `install/Installer.php::seedSettings()` 显式种子 `url_path_prefix=img`
- 新增 `install/upgrade.php` v1.3.5 段，老库 INSERT IGNORE `url_path_prefix=img`

**审查**：虾二号端到端对比 + PASS。

> 注：v1.3.5 一度改为"严格单级"（拒绝斜杠），v1.3.6 又改回"允许多级"（保留斜杠）。最终设计是支持多级（默认 `img`），用户可改单级（如 `tu`）或多级（如 `img/tu`）。

---

## [v1.3.4] - 2026-09-02

### 🐛 BUG 修复：全新安装默认值与生产环境不一致

老季要求「新安装版本跟我一样」，龙虾二号端到端对比找出 4 处不一致：

1. **F1: 目录规则 dir_rule 安装时未种子** → 新装图片落到扁平目录，**与生产 `2026/09/` 不一致**
   - 修复：`seedSettings()` 显式种子 `dir_rule=month`、`rename_rule=short`
2. **F2: 默认压缩档 balanced vs 生产 saver** → 修复：`default_compression=saver` + `web_compression_profile_id=4`
3. **F3: .nginx.conf /img/ alias 指向 storage/images**（实际 path=public 写到 public/img/）
   - 修复：去掉 /img/ alias（保留 `/uploads/` 兼容软链）
4. **F4: 安装向导密码最小长度 8** vs 安全策略 10 → 修复 step3 minlength=10

### 📝 伪静态规则：去 /uploads/ alias + 通用化（老季手测要求）

老季要求「我的和开源的文档里面必须一致」+「伪静态必须全部通用」。

- `.nginx.conf` 去掉残留的 `/uploads/` alias（路径硬编码错误）
- 加静态资源缓存 location（图片/字体/css/js/svg 30 天）
- 通用方案：`try_files $uri` + 不写 alias，依赖 nginx root 直读或符号链接
- `public/.htaccess` **不改**（已通用，Apache RewriteCond -f/-d 等价）
- README 新增「🔀 伪静态规则」章节，详细解释通用原理和升级兼容

### 审查

- 龙虾二号 50 秒复审 v1.3.4 修复 PASS
- 龙虾二号 37 秒分析通用伪静态方案 → 落地执行

---

---

## [v1.3.3] - 2026-09-02

### 🐛 BUG 修复：全新安装与开发环境行为不一致

**症状**：老季朋友全新安装后，图片上传/显示跟老季生产环境不一致（虽然能存，但行为有偏差）。

**根因**：v1.3.2 把 Installer 默认本地存储 path 设为 `storage/images`，但老季生产环境（nginx root=public）实际用 path=`public`，写到 `public/img/...`。两个行为不同，新装用户必须手动改 path。

**修复**：
- `install/Installer.php::createDefaultStorage()` — 默认 path 改回 `public`（与开发环境一致）
- 朋友新装即跟老季生产环境 100% 一致：`path=public`，文件写到 `public/img/2026/09/xxx.jpg`
- URL 自动生成：`https://域名/img/2026/09/xxx.jpg`（直接可访问，无需 nginx alias）

### 📝 README 修复：宝塔安装步骤顺序错误（老季手测发现）

**症状**：README 之前的宝塔安装步骤直接让用户把网站根目录设为 `public/`，导致访问 `/install/` 时 404（install 在项目根目录，不是 public/）。

**修复**：
- README 宝塔步骤重写为 5 步：
  1. 添加站点（根目录先默认 `freeimg`）
  2. 跑 `/install/` 安装向导
  3. 改根目录 → `public/`
  4. 粘贴伪静态
  5. 重启 + 删 install/

**审查**：龙虾二号 43 秒复审 PASS

**

---

## [v1.3.2] - 2026-09-02

### 🐛 BUG 修复：全新安装图片无法显示

**症状**：老季朋友全新安装后，上传图片无法显示。

**根因**：`Installer::createDefaultStorage()` 错误地把本地存储 `url` 写成了 `https://host/uploads`。但 `settings.url_path_prefix`（默认 `img`）已由 `LocalStorage::url()` 自动拼接，导致产生 `https://host/uploads/img/...` 这种不存在的 URL。

**修复**：
- `install/Installer.php` — `url` 改为裸域名（不带 `/uploads`）
- 路径前缀由 `settings.url_path_prefix` 统一管理（默认 `img`）
- 完整 URL 自动拼接：`baseUrl + storage_path` = `https://host/img/...`

**附带**：
- `install/upgrade.php` — v1.3.2 段新增老库存储 url 修复逻辑（幂等）
  - 跳过：JSON 解码失败（生产环境加密存储）→ 留待后台手动改

**审查**：龙虾二号 1 分 18 秒复审 PASS（含🔴 staging 同步问题）

---

---

## [v1.3.1] - 2026-09-02

### 🧹 清理：彻底删除 config.app.url / config.app.force_url

**背景**：v1.1.8.2 已经废弃 config.app.url，example 模板也不再写。但代码里还有 2 处兜底读 config.app.url（force_url=true 分支 + 恶意 host fallback）。本次彻底清理。

**改动**：
- `app/Helpers/functions.php`
  - L165 force_url=true 分支删除
  - L230 恶意 host fallback 删除 config.app.url 那行
  - 文档注释更新（去掉 config.app.force_url + app.url 描述）
- `views/settings/index.php`
  - Host 白名单提示文案更新（不再提 config.app.url）

**顺手修隐患**：本服务器 config.php 里 `app.url=https://pic.527.net`（**陈旧错误域名**）。删除读取后不再生效，**杜绝「旧配置生成错误链接」**。

**兜底**：
- 老库 config.php 残留 app.url 字段 → 无害（PHP数组多键）
- 删 force_url 分支后 admin 故意开 force_url → fallback 到 request_origin()（行为中性）
- 删恶意 host fallback 后 host 不在白名单 + site_url 空 → fallback localhost（更安全）

**审查**：龙虾二号 51 秒复审 PASS

---

---

## [v1.3.0] - 2026-09-02

### ✨ 新增：最大并发会话数限制（多端登录）

**痛点**：v1.1.5 加的 `destroyAllForUser()` 强制单点登录（每登录踢光所有端），老季手测发现两个浏览器不能同时登录。

**方案**（龙虾二号方案）：
- 默认允许 3 个并发会话，可后台配置（1-20）
- 超限自动踢掉 `last_activity_at` 最早的会话（最久没活动的）
- `destroyAllForUser()` 保留给 admin 强制下线/改密踢人用

**改动**：
- `app/Services/SessionService.php` — 新增 `enforceLimit($userId, $limit)` 方法
- `app/Controllers/AuthController.php:completeLogin()` — `destroyAllForUser()` → `enforceLimit()`
- `install/upgrade.php` — INSERT `max_concurrent_sessions=3`（v1.3.0 段）
- `app/Controllers/SecurityPolicyController.php` — 加 `max_concurrent_sessions` 到 $map（范围 1-20）
- `views/security/policy.php` — 加 number input 字段

**配置**：
- 后台 → 安全 → 安全策略 → 「最大并发会话数」（默认 3，范围 1-20）
- 设 1 等同单点登录

**兜底**：
- upgrade.php INSERT 默认值 3
- 代码 `(int)config(...) ?: 3`
- 视图 `?? 3`
- `enforceLimit` 内部 `max(1, min(20, $limit))` 防御性钳制

**审查**：龙虾二号 1 分钟复审 PASS（实测 DELETE ... LIMIT :lim 在真实 DB 执行成功）

---

---

## [v1.2.0] - 2026-09-02

### 🐛 BUG 修复：基础设置独立保存丢失 url_follow_host / allowed_hosts

**症状**：用户后台开启多域名模式 + 填 Host 白名单 → 保存 → 刷新后多域名模式被去掉、白名单丢失

**根因**：基础设置组独立保存按钮（submit_section=basic）的 `$basicOnly` 数组只包含 4 个字段（site_name/site_url/share_url/api_url），漏掉了 url_follow_host 和 allowed_hosts

**修复**：$basicOnly 增加两个字段
```php
$basicOnly = [\site_name, \site_url, \share_url, \api_url, \url_follow_host, \allowed_hosts];
```

**附带改进**：文案更新（提示用户在「下方 Host 白名单」配置，而不是去改 config.php）

**审查**：龙虾二号 20 秒复审 PASS

---

### 🧹 重大清理：config 大幅精简 + 域名生成 URL BUG 修复

#### 🧹 config 精简

删除所有"程序里完全没人用"的 config 字段（14 个字段 / 3 整节）：

| 节 | 删除 | 保留 |
|---|---|---|
| app | name | url/timezone/debug/encryption_key |
| database | collation | host/port/dbname/username/password/charset |
| session | cookie_httponly / cookie_samesite（硬编码）| name/lifetime/cookie_secure |
| upload | allowed_types（用 settings）/ chunk_size | max_size / max_pixels |
| image | **整节删除** | — |
| storage | **整节删除** | — |
| log | **整节删除** | — |

**审查**：龙虾二号 48 秒复审 PASS，老库升级无影响（多余键不被读），新库安装无 null 错误（全部 `??` 兜底）

#### 🐛 域名生成 URL BUG 修复

**症状**：老季后台设了 share_url=https://2025dns.cn，但相册分享链接仍是 pic.5276.net

**根因**：3 处 controller + 2 处 view 用 `base_url()` 生成应该用 `share_url()` / `api_url()` 的 URL

**修复**：

| 文件 | 行 | 之前 | 修复 |
|---|---|---|---|
| `AlbumController.php` | 195 | `base_url('s/' . $token)` | `share_url('s/' . $token)` |
| `views/share/image.php` | 5 | `'https://' . $_SERVER['HTTP_HOST']` | `share_url('s/img/' . ...)` |
| `views/share/folder.php` | 6 | `'https://' . $_SERVER['HTTP_HOST']` | `share_url('s/' . ...)` |
| `views/api_keys/index.php` | 262 | `base_url('api/v1/upload')` 给用户看 | `api_url('api/v1/upload')` |
| `views/api_keys/index.php` | 287 | `$apiBaseUrl = rtrim(base_url(), '/')` | `rtrim(api_url(), '/')` |

**说明**：站内跳转（`Response::redirect(base_url('albums'))`）保持用 `base_url()`（跟随当前域名，符合直觉）

---

### 🧹 清理：废弃 config.app.url 与 config.storage.local_url

**痛点**：config.app.url 和后台的 settings.site_url 是同一个值的重复配置，混乱。

**清理**：
- `config.app.url`：完全废弃，统一用 `settings.site_url`（后台改即可，不用动文件）
- `config.storage.local_url`：从来没人用，删除
- `__SITE_URL__` 占位符：移除
- `install/Installer.php::writeConfig()` 去掉 `$siteUrl` 参数

**改动**：
- `config/config.example.php`：删除 `'url' => '__SITE_URL__'` 和 `'local_url' => '/uploads'`
- `install/Installer.php` writeConfig 签名简化
- `install/index.php` 调用处同步
- `app/Helpers/functions.php` force_url 分支恢复直接读 config.app.url（虾二号指出死代码，顺手精简）

**兼容性**：
- 老库升级：现有 config.php 里残留的 url 字段不影响（仅在 force_url=true 兜底时被读）
- 新库安装：config.php 不再含 url 字段
- 唯一权威源：`settings.site_url`

**审查**：龙虾二号 36 秒复审通过（PASS）

---

### ✨ 新增：后台域名三件套（主域名 / 分享 / API）

**解决痛点**：老季想换域名不用改代码文件，直接后台改。

#### 三类域名独立可配

| 字段 | 用途 | 默认行为 |
|---|---|---|
| `site_url`（主域名） | 基础域名，图片外链基础 | **必填**（安装时自动写入访问域名） |
| `share_url`（分享域名） | `/s/{token}` 分享链接 | **留空 = 跟随主域名** |
| `api_url`（API 域名） | API 接口 + 返回的图片URL | **留空 = 跟随主域名** |

#### 域名优先级链

```
share_url → site_url → （无 fallback）
api_url   → site_url → （无 fallback）
site_url  → config.app.force_url → HTTP_HOST → localhost
```

#### 新增/修改函数

- `site_origin()` / `share_origin()` / `api_origin()` — 取 origin（scheme://host:port）
- `site_url($path)` / `share_url($path)` / `api_url($path)` — 取完整 URL
- `extract_origin($url)` — 内部 helper，带 host 字符白名单
- `base_origin()` / `base_url()` 保留兼容（重定向到 site_*）

#### 应用点

- `ShareController`：`base_url('s/...')` → `share_url('s/...')`
- `RestApiController`：`base_url()` → `api_url()`（自描述 endpoint）

#### 后台 UI 改进

- 「基础设置」组**独立保存按钮**（方便只改域名不触发其他字段）
- 底部「保存全部设置」按钮（保留原功能）
- 主域名标红星 `*` 表示必填

#### 安全校验

- `extract_origin` 正则：`#^https?://[a-zA-Z0-9.\-_:]+(/.*)?$#`
- 拒绝：`javascript:` / `data:` / `file://` / `ftp://` / 含空格 / 含 `\`
- 恶意输入 fallback 到 `http://localhost`（不抛异常）
- 自动补 `https://`（用户只填域名时）
- DB 异常静默 fallback（不阻塞页面）

#### 安装 / 升级

- **新装**：`seedSettings($_SERVER['HTTP_HOST'])` 自动写入 site_url；share_url/api_url 默认空字符串
- **老库升级**：`install/upgrade.php` v1.1.7 段自动 INSERT `share_url=''` / `api_url=''`（幂等）

#### 审查

- 龙虾二号复审：PASS（4 项关键检查全过，仅 18 秒）
- 自测：4 case fallback链全通过
- 11+ 安全测试：恶意输入全部 BLOCK

---

## [v1.1.8.1] - 2026-09-01

### ✨ 改进：Host 白名单后台可设置（allowed_hosts）

**痛点**：v1.1.8 多域名模式需要去 `config/config.php` 配 `app.allowed_hosts`，朋友是小白不方便。

**方案**：把 allowed_hosts 也搬后台，textarea 输入，每行一个域名。

#### 改动

- `request_origin()` 优先读 `settings.allowed_hosts`，fallback 到 `config.app.allowed_hosts`（兼容过渡期）
- 设置页加 textarea（每行一个域名，自动补 https://，host 字符白名单）
- SettingController 加校验逻辑（去 scheme/去尾斜杠/去 CRLF）
- upgrade.php 加 `allowed_hosts` 默认空字符串（幂等 INSERT IGNORE）
- 黄色警告条条件改为：既没 settings 又没 config 时显示

#### 行为

| settings 状态 | config 状态 | 行为 |
|---|---|---|
| 有值 | - | 用 settings（split + strip scheme）|
| 空 | 有值 | 用 config |
| 空 | 空 | 不校验（信任任意 host）|

#### 安全

- 保存时校验：每行必须是合法域名（`#^https?://[a-zA-Z0-9.\-_:]+$#`）
- 比对时 strip scheme + 小写化（兼容用户输入风格）
- 拒绝 `javascript:` / 含空格 / 含 CRLF

#### 审查

- 龙虾二号第一次复审抓到 **阻断性 BUG**：settings 存了 `https://` 前缀但比对用裸 host → 永远匹配不上
- 修复：split 后 strip scheme + 小写化
- 重测 4 case 全通过

---

## [v1.1.8] - 2026-09-01

### ✨ 新增：多域名模式开关（url_follow_host）

**痛点**：老季在宝塔里建 5 个站点指向同一图床目录，5 个域名都能访问，但 API 返回的链接、分享链接都是固定的 site_url，5 个域名访问时拿到的是同一个链接。

**方案**：单开关设计（龙虾二号方案，三开关方案被否决为过度设计）

#### 新增字段

`settings.url_follow_host`（默认 '0'）：

- `= '0'`（默认）：v1.1.7 行为不变（用 site_url / share_url / api_url 配置）
- `= '1'`：跳过 site_url/share_url/api_url 写死值，URL 跟随访问域名

#### 行为对比

| 模式 | 访问 pic.5276.net | 访问 img.xxx.com | 自定义 api_url |
|---|---|---|---|
| 默认（0）| pic.5276.net | pic.5276.net | api.zzz.com |
| 多域名（1）| pic.5276.net | **img.xxx.com** | api.zzz.com（独立）|

#### 安全

- 复用现有 `config.app.allowed_hosts` 白名单校验
- host 字符白名单清洗（防注入）
- 开启多域名模式 **必须** 在 `config/config.php` 配置 `allowed_hosts`，否则攻击者构造 Host 头 → API 返回恶意域名

#### UI

设置页基础设置组加 checkbox：「多域名模式：URL 跟随访问域名」+ 警告 + 必配 allowed_hosts 提示

#### 实现

- `site_origin()` 加 url_follow_host 分支（与现有"请求 host 兜底"合并，不重复代码）
- `share_origin()` / `api_origin()`：**一行不改**（留空已跟随 site_origin）
- SettingController 加 checkbox 处理（取消勾选时存 '0' 而非空字符串）
- upgrade.php 升级路径：INSERT 默认 '0'（幂等）

---

## [v1.1.6] - 2026-09-01

**修复**：腾讯云 COS 存储驱动（核心 Bug，已影响所有 COS 用户）+ SFTP 教程底部遮挡

### 🐛 严重 Bug 修复：腾讯云 COS 签名

（详见下方 v1.1.6-COS 部分）

### 🐛 SFTP 教程底部被遮挡修复
- **症状**：在「添加存储 → SFTP」页面，「SFTP 小白教程」折叠面板里底部内容看不到
- **根因**：教程区域误用了 `config-collapse-wrapper` 类，该类 CSS（`public/assets/style.css` L549-555）强制 `max-height: 800px + overflow: hidden`（这是给上传区折叠配置设计的），套到教程上把超出 800px 的内容全部截断
- **修复**：教程外层类名改为 `sftp-tutorial`（自定义无 CSS 类），高度由内容决定
- **审查**：龙虾二号 11/11 断言通过 + 375px 手机端验证 OK

---

- **症状**：用户使用 COS 存储时，「测试连接」一直失败，但密钥/区域都正确；华为云 OBS 同样代码结构正常
- **根因**：原代码误用 **TC3-HMAC-SHA256** 算法（腾讯云 API 3.0 JSON API 的签名），嫁接到 COS XML API（走 `x-amz-*` 头 + `myqcloud.com` 桶域名）上。COS XML API 只认 `q-sign-algorithm=sha1`，网关返回 400/403 → testConnection 失败
- **影响范围**：所有通过 COS 存储的图片上传/读取/删除全挂
- **修复**：
  - 整段重写 `signedHeaders()` 方法，改用腾讯云官方 **q-sign-algorithm=sha1** 算法
  - 删除过期的 `x-amz-content-sha256` / `x-amz-date` 头
  - StringToSign 恢复标准 3 行格式（`sha1\nKeyTime\nSHA1(HttpString)\n`）
  - Authorization 头改为官方标准 URL 参数拼接（`q-sign-algorithm&q-ak&q-sign-time&q-key-time&q-header-list&q-url-param-list&q-signature`）
- **佐证**：全网搜 `"TC3-HMAC-SHA256" + COS + x-amz-date` 零结果，没有任何真实实现这么写
- **文档**：对照官方文档 https://cloud.tencent.com/document/product/436/7778 逐项实现并复审
- **实测**：使用真实密钥（id=13 腾讯桶）实测 testConnection + 上传 + 读取 + 删除全链路通过

### 🐛 次要 Bug 修复

**PUT 上传 Content-Type 错误**
- **症状**：上传图片后，对象 MIME 是 `application/x-www-form-urlencoded`（curl 默认），不是 `image/png` → 图床直链显示异常
- **修复**：put() 显式设置 `Content-Type`，按扩展名猜 mime（jpg/png/gif/webp/mp4 等），新增 `guessMime()` 方法
- **关键**：Content-Type 不入 q-header-list 签名（COS 只强制签 host）

**UriPathname URL 编码处理不当**
- **症状**：中文/空格文件名上传 403 SignatureDoesNotMatch
- **根因**：COS 服务端会把请求行 URL-decode 后与签名的**原始路径**比对；签名里做 RFC3986 编码反而错位
- **修复**：签名 UriPathname 保留原始未编码路径，URL 单独走 `encodeUrlPath()`（RFC3986 保留 `/`）
- **实测**：中文路径 `/测试路径-cos-mime.png`、空格路径 `/my file-cos-mime.png` 都 ✅

### 📝 经验教训

- ⚠️ 腾讯云有 **两套独立签名体系**：API 3.0 JSON API 用 TC3-HMAC-SHA256（`X-TC-*` 头），XML API 用 q-sign-algorithm=sha1（`q-*-*` 头），**绝对不能混用**
- ⚠️ 看到 `TC3-HMAC-SHA256` + `x-amz-*` 头组合 = 100% 错误
- ⚠️ 龙虾二号用真实密钥实测才暴露问题（不只是代码审查，是真实调用验证）
- ⚠️ 改完代码没有马上汇报、回复用户说自己干完了 → 被骂，下次必须边干边汇报

---

## [v1.1.5] - 2026-08-31

**新增**：mega 档 13KB（追平/超越浏览器）、API 调试工具、图片批量全选。
**修复**：EXIF 泄露、URL 双斜杠 404、会话过期、压缩档精简 5 档。

---

## [1.1.4-alpha] - 2026-08-30

**新增**：6 项安全加固（像素炸弹、上传白名单、CSP 响应头等）。

### 计划中
- OSS 签名 v1 → v4 修复
- COS 签名重写（q-sign-algorithm=sha1）
- 多用户系统
- Webhook 通知
- CDN 集成

---

## [1.1.4-alpha] - 2026-08-30

Phase 9.4：6 项安全加固 + 文档准确性修正 + 升级清理。

### 🐛 修复（龙虾二号审查 6 项 P2）

#### 安全加固
- **像素炸弹防护**（`UploadService.php`）：单图 > 16MP 拦截（128M 内存下 GD 安全解码上限）
- **upload_max_size 走后端设置**（`UploadService.php`）：默认 10MB 可在后台"上传设置"调整
- **default_compression 走后端设置**（`UploadService.php`）：上传默认压缩档与后台同步
- **upload_allowed_types 白名单**（`UploadService.php`）：后台"允许的扩展名"作为附加白名单
- **存储浏览路径穿越防护**（`StorageBrowseController.php`）：双重防护（preg_replace + realpath）
- **安全响应头**（`public/index.php`）：CSP / X-Frame-Options / X-Content-Type-Options / Referrer-Policy

#### 配置一致性
- 同步 `config/config.example.php` 与线上 `config/config.php`：新增 `upload.max_pixels = 16MP`

#### 表单 + nginx 一致性
- `views/settings/index.php`：`upload_max_size` 输入 `max="20"`（与 nginx `client_max_body_size 20M` 对齐）

#### 错误信息友好化
- `UploadService::isAllowedMime` 拒绝时附带当前允许的扩展名列表

#### 升级清理（`install/upgrade.php` 新增）
- 安装完成自动清理孤儿 settings 行：`site_description` / `default_storage` / `allow_signup` / `maintenance_mode`（v1.1.3 删除但 DB 残留）

### 📖 文档

- README.md：表数 11 → 14、API Key 前缀 `ak_` → `fk_`、参数 `quality` → `compression`、`album_id` → `folder_id`
- docs/API.md：版本号 v1.1.1 → v1.1.3、压缩档 `small` → `saver`

---

## [1.1.3-alpha] - 2026-08-30

Phase 9.3：浏览器上传压缩三模式 + 压缩系统缺陷修复 + 老季数据恢复。

### ✨ 新增

#### 浏览器上传压缩三模式（后台可切换）
- 新增设置项 `browser_upload_mode`：**double / browser / backend**（默认 **double**）
- **双重压缩（double）**：前端 canvas 压缩 → 后端按 Web 默认档再压缩（体积最小）
- **仅浏览器压缩（browser）**：前端压缩 → 后端跳过（仅加水印除外）
- **仅后端压缩（backend）**：原图直传 → 后端按 Web 默认档压缩
- 后台「压缩配置」页面新增下拉切换，含三模式说明文案
- 实测：1.1MB 原图 → 双重压缩 98KB（省 91%）

#### 老季数据恢复（binlog）
- 从 `mysql-bin.000010` binlog 提取并恢复 3 张图 DB 记录（id 185/186/187，宽高/大小正确）
- 物理文件因被 rm 无法找回，需老季重新上传

### 🐛 修复（龙虾二号两轮审查 15 项）

**Phase 9.3 关键修复**
- **isFull 公式**：移除 `* 1024`（current_usage_mb 已是真实 MB）
- **original_size 防护**：拒绝伪造（claimed < realSize → 忽略；claimed > maxSize×2 → 忽略）
- **水印解耦**：`_force_watermark` 强制走压缩链（防浏览器路径绕过水印）
- **保留元数据**：详情页正确显示尺寸/原大小/节省%/压缩方法

**严重缺陷（龙虾二号第二轮 P1-P6）**
- **P1 [严重]** GIF 上传 HTTP 500/502：chain GIF 分支 output_path 用了未赋值的 `$tempIn` → 改 `$inputFile`
- **P2 [严重]** PNG/WebP/BMP 水印全部绕过（GdPngProcessor 无 watermark 代码）→ chain PNG 分支：水印开走 GdProcessor；未知 MIME 水印兜底
- **P3 [中]** 每次压缩泄漏 2 个 /tmp 文件 → chain cleanup 同时删 .final/tempIn/.cmp；UploadService 失败路径也 unlink
- **P4 [中]** "省流"档位静默失效（small vs saver 不统一）→ 统一 `saver`
- **P5 [低]** DECIMAL(12,4) 视图截断 <1MB 显示 0MB → 改 (float) + 自动单位
- **P6 [低]** 20MB 硬编码改配置；删未使用变量；删空调试文件

**存储容量显示**
- `addUsage`/`recalcUsage` 公式改 `bytes/1048576`（真实 MB）
- 字段类型 `current_usage_mb`：`bigint` → `decimal(12,4)`（支持小数累积）
- 视图自动选单位 B/KB/MB/GB（修复假 0.51 GB 显示）
- 实测：4 张图真实 291.7 KB

**其他修复**
- `setSetting` 签名放宽支持 `string|int`（修 string 设置报 TypeError 500）
- `original_mime`/`original_extension`/`compressor`/`compression_source` 字段填充
- skip_compress+水印：chain max_width=0/quality=85（不缩放只加水印，避免 q92 膨胀）
- compressor 语义：browser 链路标 browser；double 真压缩标 gd

### 🚨 事故记录

- **PHP-FPM 崩溃 3 分钟（02:55-02:58）**：reload 时遇 cleanup 边界异常，FPM master 主动终止 → 自动恢复
  - **教训**：不再主动 `kill -USR2` reload PHP-FPM（强化红线）
- **误删 3 张图物理文件**：清理测试数据时范围误判
  - DB 记录已 binlog 恢复；物理文件 rm 无法找回
  - 老季表态："现在是开发测试环境 没有真正运营 删除都没事"

---

## [1.1.2-alpha] - 2026-08-30

Phase 9.2：压缩系统全面重构。

### ✨ 新增

#### 压缩系统重构（CompressionChain 策略链）
- **新增** `app/Processors/GdPngProcessor.php` — GD palette 量化 PNG 压缩（替代不可用的 pngquant CLI）
- **新增** `app/Processors/CompressionChain.php` — 主入口，按 MIME 分发
- 策略：
  - JPEG/WebP → GD `imagejpeg` + target_size 二分
  - PNG → GD `imagetruecolortopalette` + zlib level 9（按 quality 选 32/64/128/256 色）
  - GIF → 原样保留
- **小图也压缩**（不再跳过 < max_dimension 的图）
- **压完变大自动保留原图** + `compressor=original, source=none`
- **保留 alpha 通道**（GD palette 模式自动转 PaletteAlpha）

#### 数据库扩展
- `compression_profiles` 加 `png_quality_min` / `png_quality_max` 字段（独立调 PNG quality）
- `images` 加 4 字段：
  - `original_mime`（真实 MIME，不被扩展名欺骗）
  - `original_extension`（原始扩展名）
  - `compressor`（gd / gd-palette / pngquant / original）
  - `compression_source`（browser / api-server / none）
- `install/Installer.php` 新增 `seedCompressionProfiles()`（6 个内置预设 + PNG quality 范围）

#### 后台详情增强
- `views/images/show.php` 显示压缩元数据：
  - 原始 MIME vs 当前 MIME
  - 原扩展名 → 现扩展名
  - 压缩器（gd-palette / original 等）
  - 压缩源（api-server / browser / none）
  - 节省% + "未压缩/已压缩" 标识

#### 回归测试
- **新增** `tests/compression.php` — 8 张测试图 × 5 档位 = 40 用例
- 覆盖：大/小 JPEG、PNG 截图、透明 PNG、伪 jpg 真 png、GIF、WebP
- 输出 Markdown 格式对比表
- 验证：真实 MIME、扩展名、压缩率、不变大原则

### 🐛 修复

- **伪 jpg 真 png 文件** —— 之前的 `compress()` 用 `processor->info()['extension']`，正确识别但**不重新压缩**。现在 CompressionChain 检测真实 MIME 后调用对应处理器，**正确处理 PNG**。
- **compression_profiles 字段未真生效** —— 之前 `imagejpeg()` 只用 `quality` 一个参数。现在上传用 **JPEG/WebP/PNG 独立 quality**，Profile 全字段真生效。
- **小尺寸图被跳过压缩** —— 之前 max_dimension 0 = 不缩就直接 return。现在小图也**重新编码**（GD palette PNG、JPEG quality 重压）。
- **imagedestroy deprecation**（PHP 8.5）—— 移除非必要调用（实际 PHP 8.0+ 已无效果）。
- **PHP `exec()` 禁用**——宝塔默认禁用 `exec/shell_exec/proc_open` 等系统调用，无法调 pngquant CLI。**方案B**：用纯 GD palette 量化替代，实测效果与 pngquant 几乎一致。

### 📝 文档
- README.md v1.1.2 路线图更新

### 实测压缩效果（老季那张 RGBA 截图 40591B）

| 档位 | 原 KB | 最终 KB | 节省% |
|---|---|---|---|
| original | 40.6 | 37.1 | 8.6% |
| high | 40.6 | 37.1 | 8.6% |
| balanced | 40.6 | **15.7** | **61.2%** |
| small | 40.6 | 15.7 | 61.2% |
| **extreme** | 40.6 | **14.4** | **64.6%** |

之前老季抱怨"压缩只省20%"——现在**省64%**。

---

## [1.1.1-alpha] - 2026-08-29

Phase 8.1 小修 + API Key 管理优化 + 域名动态化。

### ✨ 新增

#### API Key 页面升级
- **顶部红色安全 banner**：endpoint 永久 URL、Access/Secret 等同密码、每方独立 Key、定期轮换
- **顶部 3 张统计卡**：总密钥 / 已启用 / 已禁用（实时统计）
- **每 Key 卡片化**：彩色色条（绿=启用 / 灰=禁用）
- **名称可点击直接改**：失焦自动保存（绿色高亮闪烁）
- **压缩预设可改**：下拉选完立即保存，无需重新生成 Key
- **每 Key 折叠调用方式**：cURL / PicGo / ShareX 三种示例 + 复制按钮
- **Toast 提示**：右下角 2.5s 自动消失（success 绿 / error 红）
- **独立 CSS 文件** `public/assets/api-keys.css` + `api-keys-extra.css`（避免主 style.css 过大）

#### API 友好化
- `GET /api/v1/upload` 不再 404，返回友好 JSON 提示 + curl 示例
- API 支持 `Accept: application/json` + `X-Requested-With: XMLHttpRequest` header

#### 域名动态化
- `base_url()` 改用 `base_origin()` 动态生成
- 优先级：`config.app.force_url`（强制开关） > `X-Forwarded-Proto`（CDN 友好） > `HTTPS` > 当前 host
- 多值 `X-Forwarded-Host` 取首段（防 `a.com, b.com` 串联注入）
- scheme 白名单 `^https?$`（防 `onmouseover=alert(1)` 注入）
- 支持 `app.allowed_hosts` 白名单（生产推荐）
- host 字符白名单（防注入）

#### 上传页优化
- 压缩档位默认从 `settings.web_compression_profile_id` 读，不再写死
- 选项 `value` 用数据库 `code`（修 `saver` → `small` 不一致 bug）
- hint 文案改为 "默认档来自后台「压缩配置 → Web 默认档」设置"

### 🐛 修复

#### API Keys 页面
- **致命**：视图用 `Db::fetchOne` 缺 `use` 导致整个 `/api-keys` 页 500（session 存 access_key 修）
- **致命**：删除/禁用按钮无反应（防御性 JS + try/catch + toast 兜底）
- **致命**：`base_url()` 带尾斜杠 → fetch URL `//api-keys/delete` 双斜杠 nginx 404（layout + index 都 rtrim）
- **中**：删除按钮即使 404 HTML 也会被原样塞进 toast 红框（apiPost 检查 content-type 截断友好提示）
- **中**：toggle action 无白名单（非法值误禁用）
- **中**：create() 无 profile 存在性校验 + expires_at 格式（injection / 1970 误过期）
- **中**：edit() profile enabled 校验缺失 + 失配下拉静默重置
- **低**：ShareX 示例无复制按钮
- **低**：apiPost 无 `X-Requested-With`（会话过期拿到登录页 HTML）
- **低**：blur + change 双触发冗余请求
- **低**：响应键不一致（edit 用 `ok`，toggle/delete 用 `success`）

#### 上传压缩
- API 上传 `compression` 参数优先级修正：opts > apiKey 关联 > settings.api > settings.web
- 测试时 SHA256 dedup 命中返回最早图导致看上去"参数不生效"

### 🔧 性能 & 安全
- `ImageController` 405 行超 400 红线 → 拆出 `BatchImageController`（125 行）
- 所有 fetch 加 `Accept: application/json` + `X-Requested-With` header
- apiPost 检查 content-type：404 / 403 / 401 / 302 走友好提示，HTML 截断 80 字符
- JS IIFE 包装 + `window.error` / `unhandledrejection` 全局捕获

### 📝 文档
- `CHANGELOG` 更新：v1.1.1-alpha
- API Key 页面用户友好提示完善

---

## [1.1.0-alpha] - 2026-08-29

### ✨ Phase 8：多存储 + 容量管理

- **多存储驱动**：单 Local 隐藏下拉，多存储显示下拉
- **手动选存储**：上传页 `<select name="storage_id">` 选 Local/S3/SFTP
- **智能 fallback**：先 visible 未满 → 再 hidden 未满 → 全满报错
- **优先级排序**：`priority` 字段 DESC
- **可见性控制**：`visible_in_upload` 标记（不勾选 = 隐藏但可作 fallback）
- **容量限额 + 80% 阈值**：`max_capacity_mb` × 0.8 触发"已满"
- **自动容量统计**：`addUsage` / `subUsage` / `recalcUsage`（存 KB 避免小图精度丢失）
- **后台重算按钮**：`/storages/recalc` 从 images 表实际 final_size 统计

### 🐛 修复
- **致命**：upload.js 不提交 `storage_id`（手动选失效）
- **致命**：isFull 单位错配（KB vs MB → 8MB 用了 80% 判已满）
- **中**：视图容量单位（KB/1024 显示成 GB 错）
- **中**：表单 visible 默认不勾选（新加存储默认隐藏）
- **中**：batchDestroy / emptyRecycle 不减容量
- **低**：pickForUpload fallback 跳隐藏存储
- **低**：addUsage 无回滚（接受）

### 🔧 数据库迁移
- `storages` 表加 4 字段：`priority` / `visible_in_upload` / `max_capacity_mb` / `current_usage_mb`
- users 表已扩展（Phase 7）保持兼容

### 📝 文档
- README 更新：Phase 8 多存储说明
- CHANGELOG 更新：v1.1.0-alpha

---

## [1.0.0-alpha] - 2026-08-29

首个公开测试版本。

### ✨ 新增

#### 上传 & 处理
- **拖拽 / 粘贴 / 批量上传**（多文件并发）
- **浏览器 canvas 预压缩**（上传前在客户端压缩，节省流量）
- **服务端 GD 二次压缩**（6 档：原图/高清/均衡/省流/极限/自定义）
- **水印系统**：
  - 文字水印（字体/大小/颜色/透明度/角度/位置/边距）
  - 图片水印（PNG/JPG/WebP 透明底）
  - 优先级：图片 > 文字
- **重命名规则**：4 种（短名 / 时间戳 / 原文件名 / 自定义占位符）
- **目录规则**：6 种（无 / 年 / 月 / 日 / ymd / 自定义）
- **SHA256 去重**

#### 存储
- **本地存储**（默认，零依赖）
- **S3 兼容驱动**（AWS S3 / Cloudflare R2 / Backblaze B2 / 华为云 OBS / Wasabi / MinIO）
- **SFTP 驱动**（ed25519 密钥 + 密码双认证）
- **OSS / COS 驱动接口预留**（待签名重写）
- **后台存储驱动管理**（CRUD + 连接测试 + 小白教程）

#### 安全
- **2FA 两步验证**（Google Authenticator 标准 TOTP，RFC 6238）
- **登录失败锁定**（5 次失败锁 15 分钟，可配置）
- **密码强度强制**（10 位+ 含大小写+数字+符号）
- **密码历史防重用**（最近 5 个）
- **DB-backed 会话**（滑动过期，强制下线）
- **登录日志**（30 天自动清理）
- **安全策略**（会话超时/锁定时长/密码强度/2FA 颁发者，仅管理员可配）
- **账户资料**（改用户名/邮箱/密码统一入口）

#### 资源管理
- **文件夹**（树形分类）
- **虚拟相册**（picker 多选批量添加，二级目录浏览）
- **分享链接**（token + 有效期 + 访问密码 + 公开页）
- **回收站**（30 天软删除）
- **存储扫描清理**（dry-run 预览 + 二次确认，防误删）

#### API
- **REST API**（PicGo / ShareX / Typora 兼容）
- **API Key 差异化压缩**（每个 Key 可绑定独立档位）
- **完整 API 文档**（`docs/API.md` 227 行）

#### 其他
- **PHP 8.0+ 零 Composer 依赖**（纯标准库）
- **安装向导**（4 步：环境检测 → 数据库配置 → 初始化 → 创建管理员）
- **完整后台**：仪表盘 + 图片管理 + 压缩配置 + 存储管理 + API 管理

### 🔧 改进
- **代码质量红线**：所有 PHP 文件严格 < 400 行（强制模块化）
- **MVC 分层**：Controller / Service / Repository / Driver 清晰分离
- **配置驱动**：所有设置在后台可改，DB 存储
- **安全加固**：
  - CSRF 保护（所有 POST 表单）
  - SQL 注入防护（PDO 命名占位符）
  - XSS 防护（`h()` 统一转义）
  - 路径穿越防护（`str_replace('..', '')`）
  - 暴力破解锁定（5 次/15 分钟）

### 🐛 修复

详见各次龙虾二号审查报告：
- 4 个上传 bug（folder_id 写入 / 相册 picker / 水印 500 / API 水印）
- 1 个致命 bug（UploadService 类名错误导致 500）
- 多个清理工具 bug（csrf input 缺失 / 误删防护）
- 多个安全 bug（2FA 无限流 / 改密 500 / session fixation）

### 📊 性能
- 浏览器端预压缩节省 60-80% 上传流量
- 服务端智能跳过已小图（不再二次编码）
- 滑动过期只每分钟写一次 DB（避免频繁 IO）

### ⚠️ 已知问题
- OSS PUT 缺 Content-Type（待修）
- COS 签名错误（待重写）
- 无 CDN 集成
- 单管理员（DB 已预留多用户）

### 🔐 安全提示
- 2FA 启用后请**立即保存 10 个备份码**（丢手机救命用）
- 密码强度至少 10 位 + 大小写 + 数字
- 定期检查登录日志（后台 → 🔐 安全 → 📋 登录日志）

### 📝 完整变更记录

#### [1.0.0-alpha] 内部迭代
- **v0.1** - 项目初始化（安装向导 + 登录 + 设置）
- **v0.2** - 上传 + 文件夹 + 列表 + 回收站
- **v0.3** - 多云存储 + API Key + REST API
- **v0.4** - 相册 + 分享 + 公开页
- **v0.5** - 图片水印 + 文字水印
- **v0.6** - 重命名规则 + 目录规则 + 压缩配置 UI
- **v0.7** - 存储驱动后台管理（SFTP/S3 后台可配）
- **v0.8** - 存储扫描清理（dry-run + 二次确认）
- **v0.9** - 安全改造（2FA / 锁定 / 会话 / 策略）
- **v1.0.0-alpha** - 公开测试

---

## 版本说明

- **alpha**：内部测试，可能有 bug
- **beta**：公开测试，核心功能稳定
- **stable**：生产可用
- **lts**：长期支持版本

## 升级说明

### 从 1.0.0-alpha 升级
无（首个版本）

### 未来升级
```bash
# 1. 备份
mysqldump -u root -p freeimg > backup_$(date +%Y%m%d).sql
cp -r public/storage /backup/storage_$(date +%Y%m%d)

# 2. 解压新版（不要覆盖 config/）
tar xzf freeimg-v1.1.0.tar.gz -C /tmp/
cp -rn /tmp/freeimg/* /www/wwwroot/freeimg/

# 3. 执行新迁移（如有）
mysql -u root -p freeimg < /www/wwwroot/freeimg/database/migrations/upgrade_v1.1.0.sql

# 4. 清理
rm -rf /tmp/freeimg install/install.lock  # 触发新 install? 不，install.lock 不能删
```

---

## 反馈

- 🐛 **Bug Report**：GitHub Issues
- 💡 **功能建议**：GitHub Discussions
- 📧 **邮件**：见 GitHub Profile

## 致谢

- **FreeImg 团队**（开发 + 架构）
- 龙虾二号 🦞（代码审查，基于 DeepSeek）
- 🎯 **FreeImg 团队**（产品需求 + 测试）
- 参考项目：roim-picx / Chevereto / PicGo / Cloudinary

---

**License**: MIT
**Repository**: https://github.com/yunlianw/freeimg
**Demo**: https://yourdomain.com