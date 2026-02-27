<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Finite-state machine states for the interactive CLI flow.
 *
 * Reference: internal/fsm/state.go
 *   StateSelectProductType = iota  // 0
 *   StateInputProductID            // 1
 *   StateProductAction             // 2
 *   StateSelectArticle             // 3
 */
enum State: int
{
    case SelectProductType = 0;
    case InputProductID = 1;
    case ProductAction = 2;
    case SelectArticle = 3;
}
