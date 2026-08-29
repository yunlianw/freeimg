<?php
namespace App\Services;

/**
 * 文件名生成（重命名规则）
 *
 * 规则：
 *   short      随机短名（7 位，默认）
 *   timestamp  时间戳 YmdHis + 4 位随机
 *   original   原文件名（去扩展名，安全过滤）
 *   custom     自定义格式（占位符替换）
 */
class FileNameService
{
    /** 按规则生成文件名（不含扩展名） */
    public static function build(string $rule, string $ext, string $originalName = ''): string
    {
        switch ($rule) {
            case 'timestamp':
                return date('YmdHis') . str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
            case 'original':
                return self::safeBaseName($originalName) ?: self::short();
            case 'custom':
                return self::custom((string)config('settings.rename_custom_format'), $ext, $originalName);
            case 'short':
            default:
                return self::short();
        }
    }

    /** 随机短名（7 位混合字母数字，时间戳 base62 编码 + 随机填充） */
    public static function short(): string
    {
        $chars = str_split('Aa0Bb1Cc2Dd3Ee4Ff5Gg6Hh7Ii8Jj9KkLlMmNnOoPpQqRrSsTtUuVvWwXxYyZz');
        $maxPos = count($chars);

        $value = (int)(microtime(true) * 1000) + random_int(0, 999999);

        $result = '';
        $q = $value;
        for ($i = 0; $i < 7 && $q > 0; $i++) {
            $mod = $q % $maxPos;
            $q = intdiv($q - $mod, $maxPos);
            $result .= $chars[$mod];
        }
        while (strlen($result) < 7) {
            $result .= $chars[random_int(0, $maxPos - 1)];
        }

        return $result;
    }

    /** 原文件名清洗：去非法字符 + 去扩展名 + 去首尾点/下划线 */
    public static function safeBaseName(string $name): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9._\x{4e00}-\x{9fa5}-]/u', '_', $name);
        $safe = preg_replace('/\.[a-zA-Z0-9]+$/i', '', $safe);
        return trim($safe, '._');
    }

    /** 自定义格式占位符替换 */
    public static function custom(string $format, string $ext, string $originalName = ''): string
    {
        $format = trim($format);
        if ($format === '') {
            return self::short();
        }

        $map = [
            '{date}'     => date('Ymd'),
            '{time}'     => date('His'),
            '{datetime}' => date('YmdHis'),
            '{random}'   => self::short(),
            '{uuid}'     => substr(bin2hex(random_bytes(8)), 0, 13),
            '{original}' => self::safeBaseName($originalName) ?: self::short(),
            '{ext}'      => $ext,
        ];

        $name = strtr($format, $map);
        $name = preg_replace('/[^a-zA-Z0-9._\x{4e00}-\x{9fa5}-]/u', '_', $name);
        $name = preg_replace('/\.[a-zA-Z0-9]+$/i', '', $name);
        $name = trim($name, '._');

        return $name !== '' ? $name : self::short();
    }

    /**
     * 目录规则：返回自动子目录（空 = 无子目录）
     *
     * 规则：
     *   none    无（默认）
     *   year    按年 Y
     *   month   按年月 Y/m
     *   day     按年月日 Y/m/d
     *   ymd     按日期 Ymd（单层）
     *   custom  自定义格式（占位符同 custom()，但保留斜杠做多级目录）
     */
    public static function dirRule(string $rule, string $originalName = ''): string
    {
        switch ($rule) {
            case 'year':
                return date('Y');
            case 'month':
                return date('Y/m');
            case 'day':
                return date('Y/m/d');
            case 'ymd':
                return date('Ymd');
            case 'custom':
                $format = trim((string)config('settings.dir_custom_format'));
                if ($format === '') return '';
                $map = [
                    '{date}'     => date('Ymd'),
                    '{time}'     => date('His'),
                    '{datetime}' => date('YmdHis'),
                    '{random}'   => self::short(),
                    '{uuid}'     => substr(bin2hex(random_bytes(8)), 0, 13),
                    '{original}' => self::safeBaseName($originalName) ?: self::short(),
                ];
                $dir = strtr($format, $map);
                $dir = preg_replace('/[^a-zA-Z0-9\/_\x{4e00}-\x{9fa5}-]/u', '_', $dir);
                $dir = trim($dir, '/');
                // 多级目录逐段清洗，防空段
                $segments = array_filter(explode('/', $dir), fn($s) => $s !== '');
                return implode('/', $segments);
            case 'none':
            default:
                return '';
        }
    }
}
