# 📋 Changelog

FreeImg 所有版本更新日志。

格式参考 [Keep a Changelog](https://keepachangelog.com/zh-CN/1.1.0/)，
本项目遵循 [语义化版本](https://semver.org/lang/zh-CN/) 规范。

---

## [Unreleased]

### ✨ 新增：后台站点URL设置
- **功能**：在「系统设置 → 基础设置」加「站点URL」输入框
- **留空**：自动跟随访问域名（迁移域名不用改这里）
- **填写**：所有 API 返回的URL、分享链接、图片直链都用这个域名（适合 CDN/多域名）
- **优先级**：settings.site_url > config.app.force_url > 访问域名
- **安全**：
  - 只接受 http/https scheme（拒绝 javascript:/data:/file:/ftp）
  - host 字符白名单 + 自动补 https://
  - DB 异常静默 fallback
- **安装**：自动写入 `https://{$HTTP_HOST}` 作为默认值
- **升级**：老库升级无需操作，settings.site_url 不存在时 fallback 到访问域名
- **审查**：龙虾二号超时，我手动 16 case 安全测试全通过

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