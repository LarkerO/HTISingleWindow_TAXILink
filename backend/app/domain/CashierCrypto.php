<?php
declare(strict_types=1);

namespace app\domain;

final class CashierCrypto
{
    private const SM2_ID = 'distid:1234567812345678';

    public static function generate(string $bin): array
    {
        $private = self::run($bin, [
            'genpkey',
            '-algorithm',
            'EC',
            '-pkeyopt',
            'ec_paramgen_curve:SM2',
        ]);
        $public = self::run($bin, ['pkey', '-pubout'], $private);
        self::open(
            $bin,
            self::seal($bin, $private, $public, 'self-test', ['ok' => true]),
            $private,
            $public,
            'self-test',
        );
        return ['private' => $private, 'public' => $public];
    }

    public static function validatePublic(string $bin, string $value): string
    {
        $value = trim(str_replace("\r\n", "\n", $value)) . "\n";
        if (
            !preg_match(
                '/\A-----BEGIN PUBLIC KEY-----\n[A-Za-z0-9+\/=\n]+-----END PUBLIC KEY-----\n\z/',
                $value,
            ) ||
            strlen($value) > 400
        ) {
            throw new AppError('INVALID_KEY', '请输入 SM2 公钥 PEM');
        }
        $encoded = preg_replace('/-----[^\n]+-----|\s+/', '', $value);
        $der = base64_decode($encoded, true);
        $prefix = hex2bin('3059301306072a8648ce3d020106082a811ccf5501822d03420004');
        if ($der === false || strlen($der) !== 91 || !str_starts_with($der, $prefix)) {
            throw new AppError('INVALID_KEY', '请输入 SM2 曲线的 256 位非压缩公钥');
        }
        try {
            $test = self::withFiles(
                ['key' => $value],
                fn($f) => self::run(
                    $bin,
                    ['pkeyutl', '-encrypt', '-pubin', '-inkey', $f['key']],
                    str_repeat('x', 32),
                ),
            );
            // SM2 ciphertext is an ASN.1 sequence with two coordinates and two octet strings.
            if (strlen($test) < 130 || strlen($test) > 145 || ord($test[0]) !== 0x30) {
                throw new \RuntimeException('Invalid SM2 ciphertext');
            }
        } catch (\RuntimeException) {
            throw new AppError('INVALID_KEY', '无法读取 SM2 公钥');
        }
        return $value;
    }

    public static function seal(
        string $bin,
        string $private,
        string $peer,
        string $purpose,
        array $data,
    ): array {
        $key = random_bytes(32);
        $iv = random_bytes(12);
        $cipher = openssl_encrypt(
            Db::json($data),
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $purpose,
            16,
        );
        if ($cipher === false) {
            throw new \RuntimeException('Encryption failed');
        }
        return self::withFiles(['private' => $private, 'peer' => $peer], function ($f) use (
            $bin,
            $key,
            $iv,
            $tag,
            $cipher,
            $purpose,
        ) {
            $wrapped = self::run(
                $bin,
                ['pkeyutl', '-encrypt', '-pubin', '-inkey', $f['peer']],
                $key,
            );
            $envelope = [
                'key' => base64_encode($wrapped),
                'iv' => base64_encode($iv),
                'tag' => base64_encode($tag),
                'data' => base64_encode($cipher),
            ];
            $envelope['signature'] = base64_encode(
                self::run(
                    $bin,
                    ['dgst', '-sm3', '-sign', $f['private'], '-sigopt', self::SM2_ID],
                    self::message($purpose, $envelope),
                ),
            );
            return $envelope;
        });
    }

    public static function open(
        string $bin,
        array $envelope,
        string $private,
        string $peer,
        string $purpose,
    ): array {
        $raw = [];
        foreach (['key', 'iv', 'tag', 'data', 'signature'] as $name) {
            $base64 = $envelope[$name] ?? null;
            if (
                !is_string($base64) ||
                strlen($base64) > 131072 ||
                ($value = base64_decode($base64, true)) === false ||
                base64_encode($value) !== $base64
            ) {
                throw new AppError('INVALID_ENVELOPE', '无效加密报文', 502);
            }
            $raw[$name] = $value;
        }
        if (strlen($raw['iv']) !== 12 || strlen($raw['tag']) !== 16) {
            throw new AppError('INVALID_ENVELOPE', '无效加密报文', 502);
        }
        try {
            return self::withFiles(
                ['private' => $private, 'peer' => $peer, 'sig' => $raw['signature']],
                function ($f) use ($bin, $envelope, $raw, $purpose) {
                    self::run(
                        $bin,
                        [
                            'dgst',
                            '-sm3',
                            '-verify',
                            $f['peer'],
                            '-sigopt',
                            self::SM2_ID,
                            '-signature',
                            $f['sig'],
                        ],
                        self::message($purpose, $envelope),
                    );
                    $key = self::run(
                        $bin,
                        ['pkeyutl', '-decrypt', '-inkey', $f['private']],
                        $raw['key'],
                    );
                    if (strlen($key) !== 32) {
                        throw new \RuntimeException('Invalid AES key');
                    }
                    $plain = openssl_decrypt(
                        $raw['data'],
                        'aes-256-gcm',
                        $key,
                        OPENSSL_RAW_DATA,
                        $raw['iv'],
                        $raw['tag'],
                        $purpose,
                    );
                    return $plain === false
                        ? throw new \RuntimeException('Invalid ciphertext')
                        : Db::data($plain);
                },
            );
        } catch (\RuntimeException | \JsonException) {
            throw new AppError('INVALID_ENVELOPE', '报文验签或解密失败', 502);
        }
    }

    private static function message(string $purpose, array $e): string
    {
        return implode("\n", ['HTISW1', $purpose, $e['key'], $e['iv'], $e['tag'], $e['data']]);
    }

    private static function withFiles(array $contents, callable $callback): mixed
    {
        $files = [];
        $previous = umask(0077);
        try {
            foreach ($contents as $name => $data) {
                $path = tempnam(sys_get_temp_dir(), 'sm2-');
                if ($path === false) {
                    throw new \RuntimeException('Cannot allocate cryptography file');
                }
                $files[$name] = $path;
                if (file_put_contents($path, $data) !== strlen($data)) {
                    throw new \RuntimeException('Cannot write cryptography file');
                }
            }
            return $callback($files);
        } finally {
            foreach ($files as $file) {
                unlink($file);
            }
            umask($previous);
        }
    }

    private static function run(string $bin, array $arguments, string $input = ''): string
    {
        // Files avoid stdout/stderr pipe deadlocks and Windows shell quoting.
        return self::withFiles(['in' => $input, 'out' => '', 'err' => ''], function ($f) use (
            $bin,
            $arguments,
        ) {
            $process = proc_open(
                [$bin, ...$arguments],
                [
                    0 => ['file', $f['in'], 'rb'],
                    1 => ['file', $f['out'], 'wb'],
                    2 => ['file', $f['err'], 'wb'],
                ],
                $pipes,
                null,
                null,
                ['bypass_shell' => true],
            );
            if (!is_resource($process) || proc_close($process) !== 0) {
                throw new \RuntimeException('OpenSSL operation failed');
            }
            return (string) file_get_contents($f['out']);
        });
    }
}
