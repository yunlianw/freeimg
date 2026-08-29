<?php
namespace App\Support;

use App\Core\Request;
use App\Core\Response;
use App\Repositories\ApiKeyRepository;

/**
 * REST API 鉴权辅助 Trait
 * 用于 /api/v1/* 端点
 */
trait ApiAuthHelper
{
    /**
     * 提取并验证 API Key（Bearer 或双 Header）
     * @return array|null API Key 行（含 user_id / id 等），失败 null（已 Response::json 401）
     */
    protected function apiAuth(Request $request): ?array
    {
        $authHeader = $request->headers['authorization'] ?? '';
        $accessKey = '';
        $secretKey = '';
        if (preg_match('/Bearer\s+([^\s:]+):([^\s]+)/i', $authHeader, $m)) {
            $accessKey = $m[1];
            $secretKey = $m[2];
        } else {
            $accessKey = $request->headers['x-api-key'] ?? '';
            $secretKey = $request->headers['x-api-secret'] ?? '';
        }
        if (!$accessKey || !$secretKey) {
            Response::json(['success' => false, 'message' => '缺少 API Key 或 Secret'], 401);
            return null;
        }
        $key = (new ApiKeyRepository())->verify($accessKey, $secretKey);
        if (!$key) {
            Response::json(['success' => false, 'message' => 'API Key 无效或已过期'], 401);
            return null;
        }
        return $key;
    }
}
