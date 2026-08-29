<?php
namespace App\Core;

/**
 * 简易模板引擎
 * 用法：View::render('home', ['name' => 'laoniu'], 'main')
 * - 第一个参数：模板路径（不带 .php 后缀）
 * - 第二个参数：数据（变量注入）
 * - 第三个参数：布局（layouts/main.php）
 */
class View
{
    public static function render(string $template, array $data = [], ?string $layout = null): void
    {
        $templateFile = FREEIMG_ROOT . '/views/' . $template . '.php';
        if (!file_exists($templateFile)) {
            http_response_code(500);
            echo "View not found: " . h($template);
            return;
        }

        // 注入数据
        extract($data, EXTR_SKIP);

        // 开启输出缓冲捕获模板内容
        ob_start();
        require $templateFile;
        $content = ob_get_clean();

        if ($layout === null) {
            echo $content;
            return;
        }

        $layoutFile = FREEIMG_ROOT . '/views/layouts/' . $layout . '.php';
        if (!file_exists($layoutFile)) {
            echo $content;
            return;
        }

        // 布局可用 $content 变量
        require $layoutFile;
    }

    /**
     * 包含片段
     */
    public static function partial(string $name, array $data = []): void
    {
        $file = FREEIMG_ROOT . '/views/partials/' . $name . '.php';
        if (!file_exists($file)) {
            return;
        }
        extract($data, EXTR_SKIP);
        require $file;
    }
}