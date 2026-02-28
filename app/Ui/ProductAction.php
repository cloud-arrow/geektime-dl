<?php

declare(strict_types=1);

namespace App\Ui;

use App\Geektime\Dto\Course;

use function Laravel\Prompts\select;

/**
 * Interactive prompt for selecting an action after a course is loaded.
 *
 * Translated from Go: internal/ui/product_action.go
 *
 * Actions:
 *   0 — Go back to product type selection (re-select course)
 *   1 — Download all articles/videos in the course
 *   2 — Select specific articles/videos to download
 */
final class ProductAction
{
    public const ACTION_GO_BACK = 0;

    public const ACTION_DOWNLOAD_ALL = 1;

    public const ACTION_SELECT_ARTICLE = 2;

    /**
     * Display the product action selection prompt.
     *
     * @return int The selected action index (0, 1, or 2)
     */
    public static function prompt(Course $course): int
    {
        if ($course->isTextCourse()) {
            $options = [
                'go_back' => '重新选择课程',
                'download_all' => '下载当前专栏所有文章',
                'select_article' => '选择文章',
            ];
        } else {
            $options = [
                'go_back' => '重新选择课程',
                'download_all' => '下载所有视频',
                'select_article' => '选择视频',
            ];
        }

        $selected = select(
            label: sprintf('当前选中的专栏为: %s, 请继续选择：', $course->title),
            options: $options,
        );

        return match ($selected) {
            'download_all' => self::ACTION_DOWNLOAD_ALL,
            'select_article' => self::ACTION_SELECT_ARTICLE,
            default => self::ACTION_GO_BACK,
        };
    }
}
