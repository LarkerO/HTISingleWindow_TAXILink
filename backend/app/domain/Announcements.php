<?php
declare(strict_types=1);
namespace app\domain;

final class Announcements
{
    public const PLACEMENTS = ['booking', 'orders', 'profile'];

    public static function listing(?array $user, string $placement = '', int $page = 1): array
    {
        if ($placement !== '' && !in_array($placement, self::PLACEMENTS, true)) {
            throw new AppError('VALIDATION', '公告展示位置无效', 422);
        }
        $where = 'published=1' . ($user ? '' : ' AND login_required=0');
        $rows = Db::all(
            "SELECT id,title,placements,listed,login_required,updated_at FROM announcements WHERE $where ORDER BY updated_at DESC,id DESC",
        );
        $rows = array_values(
            array_filter(
                $rows,
                fn($row) => $placement === ''
                    ? (bool) $row['listed']
                    : in_array($placement, Db::data($row['placements']), true),
            ),
        );
        $page = max(1, $page);
        return [
            'items' => array_slice($rows, ($page - 1) * 30, 30),
            'total' => count($rows),
            'page' => $page,
        ];
    }

    public static function detail(?array $user, string $id): array
    {
        $row = Db::one('SELECT * FROM announcements WHERE id=? AND published=1', [$id]);
        if (!$row) {
            throw new AppError('NOT_FOUND', '公告不存在或已下架', 404);
        }
        if ($row['login_required'] && !$user) {
            throw new AppError('LOGIN_REQUIRED', '请登录后查看此公告', 401);
        }
        return $row;
    }

    public static function save(array $user, array $input, ?string $id): array
    {
        Auth::platform($user);
        $old = $id ? Db::one('SELECT * FROM announcements WHERE id=?', [$id]) : null;
        if ($id && !$old) {
            throw new AppError('NOT_FOUND', '公告不存在', 404);
        }
        $title = $input['title'] ?? '';
        $markdown = $input['markdown'] ?? '';
        $placements = $input['placements'] ?? [];
        if (
            !is_string($title) ||
            trim($title) === '' ||
            mb_strlen($title) > 120 ||
            !is_string($markdown) ||
            trim($markdown) === '' ||
            strlen($markdown) > 200000 ||
            !is_array($placements) ||
            count($placements) > 3 ||
            array_filter(
                $placements,
                fn($value) => !is_string($value) || !in_array($value, self::PLACEMENTS, true),
            )
        ) {
            throw new AppError(
                'VALIDATION',
                '请填写标题、Markdown 正文及有效展示位置（正文最多 200KB）',
                422,
            );
        }
        $row = [
            'title' => trim($title),
            'markdown' => $markdown,
            'placements' => Db::json(array_values(array_unique($placements))),
            'updated_at' => time(),
        ];
        foreach (['published', 'listed', 'login_required'] as $flag) {
            if (!is_bool($input[$flag] ?? null)) {
                throw new AppError('VALIDATION', '公告开关无效', 422);
            }
            $row[$flag] = (int) $input[$flag];
        }
        $id ??= Db::id();
        return Db::tx(function () use ($user, $old, $id, $row) {
            if ($old) {
                Db::run(
                    'UPDATE announcements SET ' .
                        implode(',', array_map(fn($key) => "$key=?", array_keys($row))) .
                        ' WHERE id=?',
                    [...array_values($row), $id],
                );
            } else {
                Db::insert('announcements', ['id' => $id, ...$row]);
            }
            Admin::audit($user, 'announcement.save', $id, [
                'title' => $row['title'],
                'published' => $row['published'],
            ]);
            return ['id' => $id, ...$row];
        });
    }
}
