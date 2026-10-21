<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A Dropbox call that failed. The message is shown to the MCP client as-is,
 * so it says what went wrong in terms of the file or folder asked for.
 */
class DropboxException extends RuntimeException {}
