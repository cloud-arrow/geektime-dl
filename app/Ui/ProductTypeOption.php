<?php

declare(strict_types=1);

namespace App\Ui;

/**
 * Represents a selectable product type option with its metadata.
 *
 * Translated from Go: internal/ui/product_type_select.go (ProductTypeSelectOption struct)
 */
final class ProductTypeOption
{
    /**
     * @param  string[]  $acceptProductTypes  Valid product type codes for validation
     */
    public function __construct(
        public readonly int $index,
        public readonly string $text,
        public readonly int $sourceType,
        public readonly array $acceptProductTypes,
        public readonly bool $needSelectArticle,
        public readonly bool $isEnterpriseMode,
    ) {}

    /**
     * Check if this product type is a university/training camp product.
     *
     * In the Go code: Index == 4 && !IsEnterpriseMode
     */
    public function isUniversity(): bool
    {
        return $this->index === 4 && ! $this->isEnterpriseMode;
    }
}
