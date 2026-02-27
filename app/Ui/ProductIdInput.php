<?php

declare(strict_types=1);

namespace App\Ui;

use function Laravel\Prompts\text;

/**
 * Interactive prompt to input a product/course ID.
 *
 * Translated from Go: internal/ui/product_id_input.go
 */
final class ProductIdInput
{
    /**
     * Display the product ID input prompt with validation.
     *
     * @param  ProductTypeOption  $selectedProductType  The currently selected product type
     * @return int The validated product ID
     */
    public static function prompt(ProductTypeOption $selectedProductType): int
    {
        $input = text(
            label: sprintf('请输入%s的课程 ID', $selectedProductType->text),
            required: true,
            validate: function (string $value): ?string {
                $trimmed = trim($value);

                if ($trimmed === '') {
                    return '课程 ID 不能为空';
                }

                if (! ctype_digit($trimmed) || (int) $trimmed <= 0) {
                    return '课程 ID 格式不合法';
                }

                return null;
            },
        );

        return (int) trim($input);
    }
}
