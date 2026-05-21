<?php

declare(strict_types=1);

namespace TrueAsync\Yii3;

/**
 * Keys for per-coroutine state stored in Async\request_context().
 *
 * Enum cases as object keys guarantee uniqueness across libraries and let
 * static analysis find every place scoped state is read or written.
 */
enum ScopedKey
{
    case REQUEST_PROVIDER;
    case CURRENT_ROUTE;
    case CURRENT_USER;
    case SESSION;
    case VIEW_STATE;
    case DB_TX_NESTING;
    case DB_ROLLBACK_ONLY;
    case DB_IN_BEGIN;
}
