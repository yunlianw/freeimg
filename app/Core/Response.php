<?php
namespace App\Core;

/**
 * 响应封装
 */
class Response
{
    public static function json($data, int $code = 200): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Powered-By: FreeImg');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function view(string $template, array $data = [], ?string $layout = 'main'): void
    {
        View::render($template, $data, $layout);
    }

    public static function redirect(string $url, int $code = 302): void
    {
        header('Location: ' . $url, true, $code);
        exit;
    }

    public static function text(string $content, int $code = 200): void
    {
        http_response_code($code);
        header('Content-Type: text/plain; charset=utf-8');
        echo $content;
        exit;
    }

    public static function html(string $content, int $code = 200): void
    {
        http_response_code($code);
        header('Content-Type: text/html; charset=utf-8');
        echo $content;
        exit;
    }
}