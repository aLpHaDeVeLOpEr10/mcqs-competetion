<?php

namespace App\Exceptions\Payments;

use RuntimeException;

/**
 * A deposit request that cannot be accepted. The message is safe to show the player.
 */
class DepositException extends RuntimeException {}
