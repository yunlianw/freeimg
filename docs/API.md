# FreeImg REST API 文档

适用版本：FreeImg v1.1.3-alpha+

---

## 一、概览

FreeImg 提供完整 REST API（路径前缀 `/api/v1/`），支持：

- **图片上传**（PicGo / ShareX / 帝国CMS / Typora 等）
- **图片列表**（编辑器"从图床选图"）
- **图片详情 / 删除**
- **文件夹列表**

**API 端点总览**：

| 方法 | 路径 | 说明 |
|---|---|---|
| POST | `/api/v1/upload` | 上传图片 |
| GET | `/api/v1/upload` | 友好提示（避免浏览器 GET 404） |
| GET | `/api/v1/images` | 列出图片（分页 + 过滤） |
| GET | `/api/v1/images/{id}` | 单图详情 |
| DELETE | `/api/v1/images/{id}` | 删除图片（REST 标准） |
| POST | `/api/v1/images/{id}/delete` | 删除图片（兼容老客户端） |
| GET | `/api/v1/folders` | 文件夹列表 |

---

## 二、认证

每个 API Key 由两段组成：

| 字段 | 用途 | 哪里用 |
|---|---|---|
| `access_key` | 公开标识 | HTTP Header |
| `secret_key` | 私密凭证 | 只在创建时显示一次 |

两种认证方式（任选其一）：

### 方式 A：Bearer Token（PicGo / 帝国CMS 推荐）

```
Authorization: Bearer ACCESS_KEY:SECRET_KEY
```

例：
```
Authorization: Bearer fk_4f6c24479fbae33a:sk_xxxxx
```

### 方式 B：双 Header

```
X-API-Key: ACCESS_KEY
X-API-Secret: SECRET_KEY
```

---

## 三、创建 API Key

1. 登录 FreeImg 后台：`https://your-domain/api-keys`
2. 填写名称（如 "PicGo 客户端" / "帝国CMS 站点"）
3. 选择压缩预设（可绑定专属 Profile，或选"跟随系统 API 默认"）
4. 提交后会显示 **Access Key** 和 **Secret Key**（Secret Key 仅显示一次，务必保存！）

---

## 四、通用响应格式

### 成功

```json
{
  "success": true,
  // 其他字段按端点而定
}
```

### 失败

```json
{
  "success": false,
  "message": "错误说明"
}
```

### HTTP 状态码

| 状态 | 含义 |
|---|---|
| 200 | 成功 |
| 400 | 参数错误（如缺文件、参数格式错） |
| 401 | 认证失败（缺 Key / Key 无效 / 过期） |
| 404 | 资源不存在 |
| 500 | 服务器内部错误 |

所有响应 `Content-Type: application/json`。

---

## 五、上传图片

### 端点

```
POST https://your-domain/api/v1/upload
```

### 请求参数（multipart/form-data）

| 字段 | 类型 | 必填 | 说明 |
|---|---|---|---|
| `file` 或 `image` 或 `files` | File | 是 | 图片文件（支持 JPG/PNG/GIF/WebP/BMP） |
| `compression` | String | 否 | 覆盖默认压缩：`original` / `high` / `balanced` / `saver` / `extreme` |
| `folder_id` | Integer | 否 | 目标文件夹 ID（先在 FreeImg 后台创建文件夹） |
| `is_public` | 0 / 1 | 否 | 默认 1（公开直链） |

### 响应

成功：
```json
{
  "success": true,
  "duplicate": false,
  "image": {
    "id":           126,
    "url":          "https://your-domain/img/g7SAq9k.jpg",
    "name":         "big.jpg",
    "width":        2048,
    "height":       1365,
    "size":         121046,
    "mime":         "image/jpeg",
    "storage_path": "img/g7SAq9k.jpg",
    "sha256":       "a330789933b7b678..."
  }
}
```

**字段说明**：

| 字段 | 类型 | 说明 |
|---|---|---|
| `id` | int | 图片 ID（删除/查询用） |
| `url` | string | **公开访问 URL**（直接给 `<img src>` 用） |
| `name` | string | 原始文件名 |
| `width` | int | 宽度（像素） |
| `height` | int | 高度（像素） |
| `size` | int | 最终文件大小（字节） |
| `mime` | string | MIME 类型 |
| `storage_path` | string | 存储路径（带 prefix，如 `img/xxx.jpg`） |
| `sha256` | string | SHA256 哈希（去重用） |
| `duplicate` | bool | `true` = 文件已存在，返回了原记录（未写入新文件） |

### 失败示例

```json
{ "success": false, "message": "缺少 API Key 或 Secret" }
{ "success": false, "message": "API Key 无效或已过期" }
{ "success": false, "message": "未收到上传文件" }
{ "success": false, "message": "压缩失败: ..." }
```

---

## 六、列出图片（帝国CMS 编辑器选图用）

### 端点

```
GET https://your-domain/api/v1/images?page=1&per_page=30&folder_id=5
```

### Query 参数

| 参数 | 类型 | 默认 | 说明 |
|---|---|---|---|
| `page` | int | 1 | 页码 |
| `per_page` | int | 30 | 每页数量（最大 100） |
| `folder_id` | int | - | 限制在指定文件夹（可选） |

### 响应

```json
{
  "success": true,
  "total": 12,
  "page": 1,
  "per_page": 30,
  "images": [
    {
      "id":               126,
      "uuid":             "5f9dd2ac-9a37-4316-9655-4953ec626865",
      "url":              "https://your-domain/img/g7SAq9k.jpg",
      "name":             "big.jpg",
      "stored_name":      "g7SAq9k.jpg",
      "extension":        "jpg",
      "mime":             "image/jpeg",
      "width":            2048,
      "height":           1365,
      "size":             121046,
      "original_size":    295722,
      "compression_ratio": 0.41,
      "folder_id":        null,
      "is_public":        1,
      "sha256":           "a330789933b7b678...",
      "created_at":       "2026-08-29 21:24:18"
    }
  ]
}
```

`total` = 当前用户的全部 active 图片总数（不受 page 影响）。

---

## 七、获取单图

### 端点

```
GET https://your-domain/api/v1/images/{id}
```

### 响应

```json
{
  "success": true,
  "image": {
    "id":               126,
    "uuid":             "5f9dd2ac-9a37-4316-9655-4953ec626865",
    "url":              "https://your-domain/img/g7SAq9k.jpg",
    "name":             "big.jpg",
    "stored_name":      "g7SAq9k.jpg",
    "extension":        "jpg",
    "mime":             "image/jpeg",
    "width":            2048,
    "height":           1365,
    "size":             121046,
    "original_size":    295722,
    "compression_ratio": 0.41,
    "folder_id":        null,
    "is_public":        1,
    "sha256":           "a330789933b7b678...",
    "storage_path":     "img/g7SAq9k.jpg",
    "created_at":       "2026-08-29 21:24:18"
  }
}
```

不存在的 ID 返回 `404 { "success": false, "message": "图片不存在" }`。

---

## 八、删除图片

### 端点（两种方式）

```
DELETE https://your-domain/api/v1/images/{id}
POST https://your-domain/api/v1/images/{id}/delete
```

### 响应

```json
{ "success": true, "message": "已删除", "id": 126 }
```

不存在的 ID 返回 404。物理文件 + DB 记录都会删除，并扣减 storage 用量。

---

## 九、文件夹列表

### 端点

```
GET https://your-domain/api/v1/folders
```

### 响应

```json
{
  "success": true,
  "folders": [
    {
      "id":          5,
      "name":        "测试",
      "parent_id":   null,
      "path":        "测试",
      "description": null,
      "created_at":  "2026-08-29 08:42:21"
    }
  ]
}
```

按 `id ASC` 排序，**不含已删除**（`deleted_at IS NULL`）。

---

## 十、压缩行为

按 4 级优先级解析压缩配置（`compression_profile_id` 选最高优先级）：

1. **请求参数 `compression`**：本次上传单独指定
2. **API Key 专属 `compression_profile_id`**：此 Key 默认使用
3. **系统全局 API 默认** `settings.api_compression_profile_id`
4. **系统全局 Web 默认** `settings.web_compression_profile_id`

合法档位 code：

| code | 中文 | 长边 px | JPEG q |
|---|---|---|---|
| `original` | 原图 | 不缩 | 不压 |
| `high` | 高清 | 2048 | 85 |
| `balanced` | 均衡 | 1600 | 70 |
| `saver` | 省流 | 1200 | 55 |
| `extreme` | 极限省流 | 900 | 40 |

---

## 十一、PicGo 配置（推荐）

### 步骤

1. 安装 PicGo：https://github.com/Molunerfinn/PicGo
2. 安装插件：`picgo-plugin-custom-uploader`
3. 打开 PicGo → 插件设置 → custom-uploader → 添加：

| 字段 | 值 |
|---|---|
| `API 地址` | `https://your-domain/api/v1/upload` |
| `自定义请求头` | `Authorization: Bearer ACCESS_KEY:SECRET_KEY` |
| `自定义 Body` | `{"compression":"saver"}` |
| `文件路径字段名` | `file` |
| `返回 JSON 中的图片 URL 字段路径` | `image.url` |

4. 测试上传：截图或拖文件到 PicGo 上传区
5. 成功 → 剪贴板自动有 `https://your-domain/img/xxx.jpg`

---

## 十二、ShareX 配置

1. 安装 ShareX：https://getsharex.com/
2. 打开 ShareX → 目标 → 自定义上传器 → 新增
3. **方法**：`POST`
4. **URL**：`https://your-domain/api/v1/upload`
5. **Headers**：`Authorization: Bearer ACCESS_KEY:SECRET_KEY`
6. **Body**：类型 `multipart/form-data`，字段名 `file`
7. **响应类型**：`JSON`，图片 URL 路径 `image.url`

---

## 十三、Typora 配置

Typora → 偏好设置 → 图像 → 上传服务 → Custom Command：

```bash
curl -s -X POST \
  -H "Authorization: Bearer ACCESS_KEY:SECRET_KEY" \
  -F "file=@$1" \
  "https://your-domain/api/v1/upload" | \
  python3 -c "import json,sys; d=json.load(sys.stdin); print(d['image']['url'])"
```

---

## 十四、帝国 CMS 集成提示

### 思路

帝国 CMS 写文章时，希望点"插入缩略图"按钮 → 弹窗 → 显示已上传的图片列表 → 选一个 → URL 填到 `titlepic` 字段。

### 步骤

1. **创建专用 Key**：在 FreeImg 后台 → API Key → 创建名为"帝国CMS" → 选压缩档（建议 `balanced` 或 `saver`）→ 保存
2. **帝国 CMS 插件**：
   - 后台 `e/admin/` 目录下新建插件
   - 改造编辑器"插入图片"按钮，弹窗显示：
     - 已有图片网格（调 `/api/v1/images?per_page=30`）
     - 上传新图按钮（调 `/api/v1/upload`）
     - 点击图片 → 把 `image.url` 填入 `titlepic` 输入框
3. **配置文件**：帝国插件里保存 `access_key` + `secret_key`（建议放 `e/config/config.php` 加密字段）

### 关键 API 调用

```php
// 列已上传图片
$ch = curl_init('https://your-domain/api/v1/images?per_page=30');
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . ACCESS_KEY . ':' . SECRET_KEY,
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$list = json_decode(curl_exec($ch), true);

// 上传新图
$ch = curl_init('https://your-domain/api/v1/upload');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, [
    'file' => new CURLFile($tmpPath),
    'compression' => 'balanced',
]);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . ACCESS_KEY . ':' . SECRET_KEY,
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$result = json_decode(curl_exec($ch), true);
$imageUrl = $result['image']['url']; // ← 填到帝国 titlepic 字段
```

---

## 十五、cURL 命令行示例

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

# 列出图片
curl -H "Authorization: Bearer fk_xxx:sk_xxx" \
  "https://your-domain/api/v1/images?page=1&per_page=20"

# 删除图片
curl -X DELETE -H "Authorization: Bearer fk_xxx:sk_xxx" \
  https://your-domain/api/v1/images/126

# 文件夹列表
curl -H "Authorization: Bearer fk_xxx:sk_xxx" \
  https://your-domain/api/v1/folders
```

---

## 十六、错误码参考

| HTTP | 常见原因 |
|---|---|
| 200 | 成功 |
| 400 | 未收到文件 / 参数格式错 / 压缩失败 |
| 401 | 缺 API Key / Key 无效 / 已过期 / 已禁用 |
| 404 | 图片/文件夹不存在 / 接口不存在 |
| 500 | 服务器内部错误（看 `error.log`） |

---

## 十七、安全建议

1. **Secret Key 不要硬编码**——存到密码管理器或环境变量
2. **每个 Key 设置过期时间**——后台 → API Key → 编辑
3. **不用了就 Revoke**——后台 → API Key → 禁用
4. **HTTPS 部署**——避免 Bearer token 明文传输
5. **不要把 Key 提交到 Git**——加 `.env` 到 `.gitignore`
6. **每方独立 Key**——PicGo/帝国CMS/AutoShop 各自一个，泄露可单独撤销

---

## 十八、API 版本

当前版本：`v1.1.3-alpha`

API 路径以 `/api/v1/` 为前缀。后续大版本（如 v2）会保留 v1 兼容至少 6 个月。

---

## 十九、路线图

- [ ] `POST /api/v1/albums` 创建相册
- [ ] `GET /api/v1/albums/{id}/images` 相册图片列表
- [ ] `PATCH /api/v1/images/{id}` 修改图片元数据（folder_id / is_public）
- [ ] Webhook 通知（图片上传完成推送到你的服务）
- [ ] OpenAPI 3.0 规范导出（自动生成 SDK）