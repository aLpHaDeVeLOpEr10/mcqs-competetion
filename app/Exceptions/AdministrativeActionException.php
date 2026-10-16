<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A permitted administrative action that cannot be applied in the current
 * state (e.g. demoting the last super admin). The message is shown to staff.
 */
class AdministrativeActionException extends RuntimeException {}
