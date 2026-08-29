<?php
namespace App\Services;

/**
 * TOTP 服务（Google Authenticator 标准）
 * 实现 RFC 6238，基于 HMAC-SHA1
 * 零依赖：纯 PHP 标准库
 */
class TotpService
{
    const PERIOD = 30;        // 时间步长（秒）
    const DIGITS = 6;         // 验证码位数
    const WINDOW = 1;         // ±1 步容错（防时间漂移）

    /**
     * 生成新的 base32 编码的 secret
     */
    public static function generateSecret(int $length = 32): string
    {
        // 用 random_bytes 生成随机 secret
        $secret = '';
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; // RFC 4648 base32
        $raw = random_bytes($length);
        for ($i = 0; $i < $length; $i++) {
            $secret .= $chars[ord($raw[$i]) % 32];
        }
        return $secret;
    }

    /**
     * 根据 secret 和时间戳生成 6 位验证码
     */
    public static function getCode(string $secret, ?int $timestamp = null): string
    {
        if ($timestamp === null) $timestamp = time();
        $timeStep = intdiv($timestamp, self::PERIOD);
        return self::hotp($secret, $timeStep);
    }

    /**
     * 验证用户输入的 6 位验证码（带容错窗口）
     */
    public static function verify(string $secret, string $code, ?int $timestamp = null): bool
    {
        $code = preg_replace('/\s+/', '', $code);
        if (!preg_match('/^\d{6}$/', $code)) return false;
        if ($timestamp === null) $timestamp = time();
        $timeStep = intdiv($timestamp, self::PERIOD);
        for ($i = -self::WINDOW; $i <= self::WINDOW; $i++) {
            if (hash_equals(self::hotp($secret, $timeStep + $i), $code)) {
                return true;
            }
        }
        return false;
    }

    /**
     * 生成 otpauth URI（用于二维码）
     */
    public static function getUri(string $secret, string $accountName, string $issuer = 'FreeImg'): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $accountName)
             . '?secret=' . $secret
             . '&issuer=' . rawurlencode($issuer)
             . '&digits=' . self::DIGITS
             . '&period=' . self::PERIOD
             . '&algorithm=SHA1';
    }

    /**
     * 生成二维码图片 URL（用 Google Chart API 替代，避免引入依赖）
     * 也可换其他开源 QR API
     */
    public static function getQrUrl(string $otpauthUri): string
    {
        return 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . rawurlencode($otpauthUri);
    }

    /**
     * 生成一次性备份码（10 个，每个 8 位）
     */
    public static function generateBackupCodes(int $count = 10): array
    {
        $codes = [];
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // 去掉易混字符
        for ($i = 0; $i < $count; $i++) {
            $code = '';
            for ($j = 0; $j < 8; $j++) {
                $code .= $chars[random_int(0, strlen($chars) - 1)];
            }
            // 格式化为 XXXX-XXXX 便于阅读
            $codes[] = substr($code, 0, 4) . '-' . substr($code, 4, 4);
        }
        return $codes;
    }

    /**
     * 核心 HOTP 算法（RFC 4226）
     */
    private static function hotp(string $secret, int $counter): string
    {
        // secret 是 base32 编码的，先解码
        $key = self::base32Decode($secret);
        // counter 转 8 字节大端
        $binCounter = pack('N*', 0, $counter);
        // HMAC-SHA1
        $hash = hash_hmac('sha1', $binCounter, $key, true);
        // 动态截断（RFC 4226 §5.3）
        $offset = ord($hash[19]) & 0xf;
        $code = (
            ((ord($hash[$offset]) & 0x7f) << 24) |
            ((ord($hash[$offset + 1]) & 0xff) << 16) |
            ((ord($hash[$offset + 2]) & 0xff) << 8) |
            (ord($hash[$offset + 3]) & 0xff)
        ) % (10 ** self::DIGITS);
        return str_pad((string)$code, self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Base32 解码
     */
    private static function base32Decode(string $input): string
    {
        $input = strtoupper($input);
        $input = preg_replace('/[^A-Z2-7]/', '', $input);
        $buffer = 0;
        $bitsLeft = 0;
        $output = '';
        $map = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        for ($i = 0, $len = strlen($input); $i < $len; $i++) {
            $val = strpos($map, $input[$i]);
            if ($val === false) continue;
            $buffer = ($buffer << 5) | $val;
            $bitsLeft += 5;
            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $output .= chr(($buffer >> $bitsLeft) & 0xff);
            }
        }
        return $output;
    }
}