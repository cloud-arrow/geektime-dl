<?php

declare(strict_types=1);

namespace App\Ui;

use function Laravel\Prompts\select;

/**
 * Interactive product type selection prompt.
 *
 * Translated from Go: internal/ui/product_type_select.go
 *
 * Product types (non-enterprise):
 *   0: 普通课程 (Normal Course)  -- sourceType=1, accepts c1/c3
 *   1: 每日一课 (Daily Lesson)   -- sourceType=2, accepts d
 *   2: 公开课 (Open Course)      -- sourceType=1, accepts p35/p29/p30
 *   3: 大厂案例 (Case Study)     -- sourceType=4, accepts q
 *   4: 训练营 (Training Camp)    -- sourceType=5, accepts empty (university)
 *   5: 其他 (Other)              -- sourceType=1, accepts x/c6
 *
 * Enterprise mode:
 *   0: 训练营 (Training Camp)    -- sourceType=5, accepts c44
 */
final class ProductTypeSelect
{
    /**
     * Display the interactive product type selection prompt.
     *
     * Returns the selected ProductTypeOption based on user choice.
     */
    public static function prompt(bool $isEnterprise): ProductTypeOption
    {
        $options = self::buildOptions($isEnterprise);

        // Build label => index map for the select prompt
        $labels = [];
        foreach ($options as $option) {
            $labels[$option->index] = $option->text;
        }

        $selectedIndex = select(
            label: '请选择想要下载的产品类型',
            options: $labels,
        );

        return $options[(int) $selectedIndex];
    }

    /**
     * Build the list of product type options.
     *
     * @return ProductTypeOption[]
     */
    private static function buildOptions(bool $isEnterprise): array
    {
        if ($isEnterprise) {
            return [
                new ProductTypeOption(
                    index: 0,
                    text: '训练营',
                    sourceType: 5,
                    acceptProductTypes: ['c44'],
                    needSelectArticle: true,
                    isEnterpriseMode: true,
                ),
            ];
        }

        return [
            new ProductTypeOption(
                index: 0,
                text: '普通课程',
                sourceType: 1,
                acceptProductTypes: ['c1', 'c3'],
                needSelectArticle: true,
                isEnterpriseMode: false,
            ),
            new ProductTypeOption(
                index: 1,
                text: '每日一课',
                sourceType: 2,
                acceptProductTypes: ['d'],
                needSelectArticle: false,
                isEnterpriseMode: false,
            ),
            new ProductTypeOption(
                index: 2,
                text: '公开课',
                sourceType: 1,
                acceptProductTypes: ['p35', 'p29', 'p30'],
                needSelectArticle: true,
                isEnterpriseMode: false,
            ),
            new ProductTypeOption(
                index: 3,
                text: '大厂案例',
                sourceType: 4,
                acceptProductTypes: ['q'],
                needSelectArticle: false,
                isEnterpriseMode: false,
            ),
            new ProductTypeOption(
                index: 4,
                text: '训练营',
                sourceType: 5,
                acceptProductTypes: [''],
                needSelectArticle: true,
                isEnterpriseMode: false,
            ),
            new ProductTypeOption(
                index: 5,
                text: '其他',
                sourceType: 1,
                acceptProductTypes: ['x', 'c6'],
                needSelectArticle: true,
                isEnterpriseMode: false,
            ),
        ];
    }
}
