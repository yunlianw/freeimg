<?php
namespace App\Core;

/**
 * 请求封装
 */
class Request
{
    public string $method;
    public string $path;
    public array $query;
    public array $post;
    public array $files;
    public array $server;
    public array $cookies;
    public array $headers;
    public string $body;

    public function __construct()
    {
        $this->method   = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $this->path     = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $this->query    = $_GET ?? [];
        $this->post     = $_POST ?? [];
        $this->files    = $_FILES ?? [];
        $this->server   = $_SERVER ?? [];
        $this->cookies  = $_COOKIE ?? [];
        $this->headers  = self::parseHeaders();
        $this->body     = file_get_contents('php://input') ?: '';
    }

    /**
     * 解析 Header
     */
    private static function parseHeaders(): array
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
        }
        if (isset($_SERVER['CONTENT_LENGTH'])) {
            $headers['content-length'] = $_SERVER['CONTENT_LENGTH'];
        }
        return $headers;
    }

    public function input(string $key, $default = null)
    {
        return $this->post[$key] ?? $this->query[$key] ?? $default;
    }

    /**
     * 仅 POST 取值
     */
    public function post(string $key, $default = null)
    {
        return $this->post[$key] ?? $default;
    }

    /**
     * 仅 GET/query 取值
     */
    public function query(string $key, $default = null)
    {
        return $this->query[$key] ?? $default;
    }

    /**
     * 获取所有 POST 原始内容（JSON 等）
     */
    public function all(): array
    {
        if (str_starts_with($this->headers['content-type'] ?? '', 'application/json')) {
            $json = json_decode($this->body, true);
            if (is_array($json)) {
                return $json;
            }
        }
        return array_merge($this->query, $this->post);
    }

    public function isPost(): bool { return $this->method === 'POST'; }
    public function isGet(): bool  { return $this->method === 'GET'; }
    public function isAjax(): bool
    {
        return ($this->headers['x-requested-with'] ?? '') === 'XMLHttpRequest';
    }

    public function ip(): string
    {
        // 注意：HTTP_CF_CONNECTING_IP / X-Forwarded-For 可被客户端伪造。
        // 如要信任这些代理，必须保证仅在受控的反代/代理之后才能取到。
        // 这里默认以 REMOTE_ADDR 为主，避免无脑信任上游头。
        $remote = $this->server['REMOTE_ADDR'] ?? '';
        if (!empty($remote)) {
            return $remote;
        }
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR'] as $key) {
            if (!empty($this->server[$key])) {
                return trim(explode(',', $this->server[$key])[0]);
            }
        }
        return '0.0.0.0';
    }

    public function userAgent(): string
    {
        return $this->headers['user-agent'] ?? '';
    }
}