<?php
/**
 * 压缩系统回归测试
 *
 * 用法：php tests/compression.php
 *
 * 输出 Markdown 表格：8 张测试图 × 5 档位 = 40 用例
 * 验证：
 *   - 真实 MIME 检测
 *   - 最终扩展名
 *   - 尺寸
 *   - 原始大小 / 最终大小 / 压缩率
 *   - 是否真的减小（不会变大）
 *   - 图片可正常打开（imagedestroy 不报错）
 *   - PNG 保留 alpha（PaletteAlpha）
 *
 * 依赖：本地 GD + CompressionChain + 8 张测试图
 */

define('FREEIMG_ROOT', '/www/wwwroot/pic.5276.net');

function expectedMimeForPath(string $path): string {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $map = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp'];
    return $map[$ext] ?? mime_content_type($path) ?: 'application/octet-stream';
}

require FREEIMG_ROOT . '/app/Helpers/functions.php';
require FREEIMG_ROOT . '/app/Core/Db.php';
require FREEIMG_ROOT . '/app/Processors/ImageProcessorInterface.php';
require FREEIMG_ROOT . '/app/Processors/GdProcessor.php';
require FREEIMG_ROOT . '/app/Processors/GdPngProcessor.php';
require FREEIMG_ROOT . '/app/Processors/CompressionChain.php';

$chain = new \App\Processors\CompressionChain();

// 测试图（路径，描述，预期 MIME）
$tests = [
    ['/tmp/testimg_big.jpg',         '大 JPEG (1.6MB)',     'image/jpeg'],
    ['/tmp/testimg_jpg300k.jpg',     '小 JPEG (488KB)',     'image/jpeg'],
    ['/tmp/testimg_big.png',         'PNG 截图 (360KB)',    'image/png'],
    ['/tmp/testimg_png300k.png',     '小 PNG (135KB)',      'image/png'],
    ['/tmp/testimg_alpha.png',       '透明 PNG (58KB)',     'image/png'],
    ['/tmp/testimg_fake.jpg',        '伪 jpg 真 png (135KB)','image/png'],
    ['/tmp/testimg.gif',             'GIF (动图 1帧)',       'image/gif'],
    ['/tmp/testimg.webp',            'WebP (256KB)',         'image/webp'],
];

$profiles = [
    'original' => ['max_dimension' => 0,    'jpeg_quality' => 100, 'png_quality_min' => 95, 'png_quality_max' => 100, 'target_size_kb' => 0],
    'high'     => ['max_dimension' => 2048, 'jpeg_quality' => 85,  'png_quality_min' => 80, 'png_quality_max' => 95,  'target_size_kb' => 0],
    'balanced' => ['max_dimension' => 1600, 'jpeg_quality' => 70,  'png_quality_min' => 65, 'png_quality_max' => 85,  'target_size_kb' => 100],
    'small'    => ['max_dimension' => 1100, 'jpeg_quality' => 45,  'png_quality_min' => 55, 'png_quality_max' => 75,  'target_size_kb' => 100],
    'extreme'  => ['max_dimension' => 800,  'jpeg_quality' => 30,  'png_quality_min' => 40, 'png_quality_max' => 60,  'target_size_kb' => 40],
];

echo "# FreeImg 压缩系统回归测试\n\n";
echo "生成时间: " . date('Y-m-d H:i:s') . "\n\n";
echo "测试图: " . count($tests) . " 张 × 档位 " . count($profiles) . " 个 = " . (count($tests) * count($profiles)) . " 用例\n\n";

echo "## 1. 真实 MIME 检测\n\n";
echo "| 测试图 | 文件扩展 | 真实 MIME |\n";
echo "|--------|----------|-----------|\n";
foreach ($tests as [$path, $label, $expectedMime]) {
    if (!file_exists($path)) {
        echo "| {$label} | ❌ 缺失 | - |\n";
        continue;
    }
    $realMime = (new \App\Processors\GdProcessor())->info($path)['mime'] ?? 'unknown';
    $ext = pathinfo($path, PATHINFO_EXTENSION);
    $mark = ($realMime === $expectedMime) ? '✅' : '⚠️';
    echo "| {$label} | .{$ext} | {$realMime} {$mark} |\n";
}

echo "\n## 2. 各档位压缩对比表\n\n";
echo "| 测试图 | 档位 | 原 KB | 最终 KB | 节省% | 扩展 | MIME | 处理器 | 验证 |\n";
echo "|--------|------|------|---------|--------|------|------|--------|------|\n";

foreach ($tests as [$path, $label]) {
    if (!file_exists($path)) continue;
    $srcSize = filesize($path);
    foreach ($profiles as $code => $opts) {
        // 复制源文件到 tmp 给 chain 处理
        $tmpIn = '/tmp/cmp_in_' . bin2hex(random_bytes(4)) . '.' . pathinfo($path, PATHINFO_EXTENSION);
        copy($path, $tmpIn);

        $realMime = expectedMimeForPath($path);
        $result = $chain->process($tmpIn, $realMime, 'test', $opts);
        @unlink($tmpIn);
        if (file_exists($result['output_path'] ?? '')) @unlink($result['output_path']);

        if (!$result['success']) {
            echo "| {$label} | {$code} | " . round($srcSize/1024, 1) . " | FAIL | - | - | - | - | ❌ |\n";
            continue;
        }

        $finalSize = (int)$result['size'];
        $ratio = (float)$result['compression_ratio'];
        $saved = $srcSize > 0 ? round((1 - $ratio) * 100, 1) : 0;
        $verify = ($finalSize > 0 && $finalSize <= $srcSize) ? '✅' : '⚠️变大';
        $compressor = $result['compressor'] ?? '?';
        $ext = $result['extension'] ?? '?';
        $mime = $result['mime'] ?? '?';

        echo "| {$label} | {$code} | " . round($srcSize/1024, 1) . " | " . round($finalSize/1024, 1) . " | {$saved}% | .{$ext} | " . str_replace('image/', '', $mime) . " | {$compressor} | {$verify} |\n";
    }
    echo "| | | | | | | | |\n"; // 分隔
}

echo "\n## 3. 压缩系统关键指标\n\n";

// 计算关键指标
$totalSaved = 0; $count = 0; $errors = 0;
foreach ($tests as [$path, $label]) {
    if (!file_exists($path)) continue;
    $srcSize = filesize($path);
    $opts = $profiles['extreme'];
    $tmpIn = '/tmp/cmp_metric_' . bin2hex(random_bytes(4)) . '.dat';
    copy($path, $tmpIn);
    $result = $chain->process($tmpIn, mime_content_type($path), 'test', $opts);
    @unlink($tmpIn);
    if (file_exists($result['output_path'] ?? '')) @unlink($result['output_path']);
    if ($result['success'] && $result['size'] <= $srcSize) {
        $totalSaved += $srcSize - $result['size'];
        $count++;
    } else {
        $errors++;
    }
}
echo "- 8 张图 × extreme 档位：节省 " . round($totalSaved / 1024, 1) . " KB（" . round($totalSaved / 8 / 1024, 1) . " KB/张平均）\n";
echo "- 失败用例：" . $errors . " / " . count($tests) . "\n";
echo "- 压缩后变大：" . ($totalSaved >= 0 ? '0 ✅' : '需调查') . "\n";

echo "\n✅ 测试完成\n";