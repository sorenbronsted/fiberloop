<?php

namespace bronsted;

use Exception;

/**
 * Thrown into a fiber when its watcher is cancelled or the loop is stopped.
 */
class CancelledException extends Exception
{
}
