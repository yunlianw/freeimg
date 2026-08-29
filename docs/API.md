# FreeImg REST API 文档

适用版本：FreeImg v1.0+

---

## 一、概览

FreeImg 提供 REST API 端点 `/api/v1/upload`，兼容 PicGo、ShareX、Typora 等任何能 POST multipart 的工具。

---

## 二、认证

每个 API Key 由两段组成：

| 字段 | 用途 | 哪里用 |
|---|---|---|
| `access_key` | 公开标识 | HTTP Header |
| `secret_key` | 私密凭证 | 只在创建时显示一次 |

两种认证方式（任选其一）：

### 方式 A：Bearer Token（PicGo 推荐）

```
Authorization: Bearer ACCESS_KEY:SECRET_KEY
```

### 方式 B：双 Header

```
X-API-Key: ACCESS_KEY
X-API-Secret: SECRET_KEY
```

---

## 三、创建 API Key

1. 登录 FreeImg 后台：`https://your-domain/api-keys`
2. 填写名称（如 "PicGo 客户端"）
3. 选择压缩预设（可绑定专属 Profile，或选"跟随系统 API 默认"）
4. 提交后会显示 **Access Key** 和 **Secret Key**（Secret Key 仅显示一次，务必保存！）

---

## 四、上传接口

### 端点

```
POST https://your-domain/api/v1/upload
```

### 请求参数（multipart/form-data）

| 字段 | 类型 | 必填 | 说明 |
|---|---|---|---|
| `file` 或 `image` 或 `files` | File | 是 | 图片文件（支持 JPG/PNG/GIF/WebP/BMP） |
| `compression` | String | 否 | 覆盖默认压缩：`original`/`high`/`balanced`/`small`/`extreme`/`custom` |
| `folder_id` | Integer | 否 | 目标文件夹 ID |
| `is_public` | 0/1 | 否 | 默认 1（公开） |

### 响应（JSON）

成功：
```json
{
  "success": true,
  "duplicate": false,
  "image": {
    "id": 38,
    "url": "https://your-domain/img/2I2DO9k.jpg",
    "name": "api_test.jpg",
    "width": 400,
    "height": 300,
    "size": 3440,
    "mime": "image/jpeg",
    "storage_path": "img/2I2DO9k.jpg",
    "sha256": "817324829d5ae2383..."
  }
}
```

失败：
```json
{
  "success": false,
  "message": "API Key 无效或已过期"
}
```

HTTP 状态码：200（成功）、400（参数错）、401（认证失败）。

---

## 五、压缩行为

按 4 级优先级解析压缩配置：

1. **请求参数 `compression`**：本次上传单独指定
2. **API Key 专属 `compression_profile_id`**：此 Key 默认使用
3. **系统全局 API 默认**：后台设置 → 图片压缩 → API 默认压缩
4. **系统全局 Web 默认**：作为兜底

如果文件已 <= `target_size_kb` 且尺寸 <= `max_dimension`，**跳过无意义二次编码**。

---

## 六、PicGo 配置（推荐）

### 步骤

1. 安装 PicGo：https://github.com/Molunerfinn/PicGo
2. 安装插件：`picgo-plugin-custom-uploader`
3. 打开 PicGo → 插件设置 → custom-uploader → 添加：

| 字段 | 值 |
|---|---|
| `API 地址` | `https://your-domain/api/v1/upload` |
| `自定义请求头` | `Authorization: Bearer ACCESS_KEY:SECRET_KEY` |
| `自定义 Body` | `{"compression":"small"}` |
| `文件路径字段名` | `file` |
| `返回 JSON 中的图片 URL 字段路径` | `image.url` |

4. 测试上传：截图或拖文件到 PicGo 上传区
5. 成功 → 剪贴板自动有 `https://your-domain/img/xxx.jpg`

### 自定义 Body 字段说明

| 字段 | 含义 | 示例值 |
|---|---|---|
| `compression` | 压缩档位 | `original` / `high` / `balanced` / `small` / `extreme` |
| `folder_id` | 目标文件夹 ID（先在 FreeImg 后台创建文件夹） | `3` |
| `is_public` | 是否公开直链 | `1` 或 `0` |

---

## 七、ShareX 配置

### 步骤

1. 安装 ShareX：https://getsharex.com/
2. 打开 ShareX → 目标 → 自定义上传器 → 新增：
3. **方法**：`POST`
4. **URL**：`https://your-domain/api/v1/upload`
5. **Headers**：
   - `Authorization: Bearer ACCESS_KEY:SECRET_KEY`
6. **Body**：
   - 类型：`multipart/form-data`
   - 字段名：`file`
7. **响应**：
   - 类型：`JSON`
   - 图片 URL JSON 路径：`image.url`
8. 测试上传：截图 → 应自动获得 FreeImg URL 到剪贴板

---

## 八、Typora 配置

1. Typora → 偏好设置 → 图像 → 上传服务 → Custom Command
2. 命令：
```bash
curl -s -X POST \
  -H "Authorization: Bearer ACCESS_KEY:SECRET_KEY" \
  -F "file=@$1" \
  "https://your-domain/api/v1/upload" | \
  python3 -c "import json,sys; d=json.load(sys.stdin); print(d['image']['url'])"
```

---

## 九、cURL 命令行示例

```bash
# 基本上传
curl -X POST \
  -H "Authorization: Bearer fk_xxx:sk_xxx" \
  -F "file=@/path/to/image.jpg" \
  https://your-domain/api/v1/upload

# 指定压缩档位
curl -X POST \
  -H "Authorization: Bearer fk_xxx:sk_xxx" \
  -F "file=@/path/to/image.jpg" \
  -F "compression=extreme" \
  https://your-domain/api/v1/upload

# 上传到指定文件夹
curl -X POST \
  -H "Authorization: Bearer fk_xxx:sk_xxx" \
  -F "file=@/path/to/image.jpg" \
  -F "folder_id=3" \
  https://your-domain/api/v1/upload
```

---

## 十、错误码

| HTTP | 原因 |
|---|---|
| 200 | 成功 |
| 400 | 参数错误（如未收到文件、压缩失败） |
| 401 | API Key 无效或已过期 |
| 419 | CSRF token 失败（仅后台页面） |
| 500 | 服务器内部错误 |

---

## 十一、安全建议

1. **Secret Key 不要硬编码**——存到密码管理器或环境变量
2. **每个 Key 设置过期时间**——后台 → API Key → 编辑
3. **不用了就 Revoke**——后台 → API Key → 禁用
4. **HTTPS 部署**——避免 Bearer token 明文传输
5. **不要把 Key 提交到 Git**——加 `.env` 到 `.gitignore`

---

## 十二、路线图

- [ ] Phase 4：腾讯云 COS / 阿里云 OSS / 华为云 OBS 驱动
- [ ] Phase 5：SFTP 远程服务器驱动
- [ ] Phase 6：相册 + 分享 + 公开页面
- [ ] Phase 7：访问统计 + 热度分析
