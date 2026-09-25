<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A single item of an MCP product upsert batch that cannot be applied. The
 * message is returned to the client as-is, so it must say what to change.
 */
class ProductUpsertException extends RuntimeException {}
