# 📋 Changelog

FreeImg 所有版本更新日志。

格式参考 [Keep a Changelog](https://keepachangelog.com/zh-CN/1.1.0/)，
本项目遵循 [语义化版本](https://semver.org/lang/zh-CN/) 规范。

---

## [Unreleased]

### 计划中
- OSS 签名 v1 → v4 修复
- COS 签名重写（q-sign-algorithm=sha1）
- 多用户系统
- Webhook 通知
- CDN 集成

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

- 牛马一号 🐮（开发 + 架构）
- 龙虾二号 🦞（代码审查，基于 DeepSeek）
- 老季 🎯（产品需求 + 测试）
- 参考项目：roim-picx / Chevereto / PicGo / Cloudinary

---

**License**: MIT
**Repository**: https://github.com/yunlianw/freeimg
**Demo**: https://pic.5276.net