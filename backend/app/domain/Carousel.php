<?php
declare(strict_types=1);
namespace app\domain;
final class Carousel
{
    public static function read(): array
    {
        return Db::data(
            Db::one('SELECT config FROM system_settings WHERE id=?', ['carousel'])['config'] ??
                null,
        ) ?: ['items' => []];
    }
    public static function save(array $user, array $input): array
    {
        Auth::platform($user);
        $items = $input['items'] ?? null;
        if (!is_array($items) || !array_is_list($items) || count($items) > 30) {
            throw new AppError('VALIDATION', '轮播最多 30 张', 422);
        }
        $clean = [];
        foreach ($items as $item) {
            if (
                !is_array($item) ||
                !is_string($item['title'] ?? null) ||
                mb_strlen($item['title']) > 100 ||
                !is_bool($item['enabled'] ?? null) ||
                !in_array($item['placement'] ?? '', ['home', 'trip'], true) ||
                !in_array($item['type'] ?? '', ['announcement', 'url'], true)
            ) {
                throw new AppError('VALIDATION', '轮播字段无效', 422);
            }
            foreach (['width', 'height'] as $key) {
                if (!is_int($item[$key] ?? null) || $item[$key] < 40 || $item[$key] > 1600) {
                    throw new AppError('VALIDATION', '展示宽高需为 40–1600 像素整数', 422);
                }
            }
            $image = Media::imageUrl($item['image'] ?? '');
            if ($image === '') {
                throw new AppError('VALIDATION', '请选择轮播图片', 422);
            }
            $target = $item['target'] ?? '';
            if (!is_string($target)) {
                throw new AppError('VALIDATION', '跳转目标无效', 422);
            }
            if ($item['type'] === 'announcement') {
                if (!Db::one('SELECT id FROM announcements WHERE id=?', [$target])) {
                    throw new AppError('VALIDATION', '公告不存在', 422);
                }
            } elseif (
                strlen($target) > 2048 ||
                !filter_var($target, FILTER_VALIDATE_URL) ||
                !in_array(
                    strtolower(parse_url($target, PHP_URL_SCHEME) ?? ''),
                    ['http', 'https'],
                    true,
                ) ||
                parse_url($target, PHP_URL_USER) !== null
            ) {
                throw new AppError('VALIDATION', '外链必须是 HTTP/HTTPS 地址', 422);
            }
            $clean[] = array_intersect_key(
                $item,
                array_flip(['title', 'enabled', 'placement', 'type', 'width', 'height']),
            ) + ['image' => $image, 'target' => $target];
        }
        Db::tx(function () use ($clean, $user) {
            Db::run('DELETE FROM system_settings WHERE id=?', ['carousel']);
            Db::insert('system_settings', [
                'id' => 'carousel',
                'config' => Db::json(['items' => $clean]),
            ]);
            Admin::audit($user, 'carousel.save', 'carousel', ['count' => count($clean)]);
        });
        return self::read();
    }
}
