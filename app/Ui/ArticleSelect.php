<?php

declare(strict_types=1);

namespace App\Ui;

use App\Geektime\Dto\Article;

use function Laravel\Prompts\select;

/**
 * Interactive prompt for selecting a specific article from a course.
 *
 * Translated from Go: internal/ui/article_select.go
 *
 * The list prepends a "返回上一级" (go back) option at index 0.
 * Article items start at index 1, corresponding to articles[index-1].
 */
final class ArticleSelect
{
    /**
     * Display the article selection prompt.
     *
     * @param  Article[]  $articles  The list of articles in the course
     * @return int The selected index (0 = go back, 1+ = article index offset by 1)
     */
    public static function prompt(array $articles): int
    {
        // Build options with "go back" as the first item (key 0)
        $options = [0 => '返回上一级'];

        foreach ($articles as $i => $article) {
            // Article indices start at 1 (offset by the "go back" entry)
            $options[$i + 1] = $article->title;
        }

        $selected = select(
            label: '请选择文章: ',
            options: $options,
            scroll: 20,
        );

        return (int) $selected;
    }
}
