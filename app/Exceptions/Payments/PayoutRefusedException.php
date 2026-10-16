<?php

namespace App\Exceptions\Payments;

use RuntimeException;

/**
 * The payout provider definitively refused a payout (e.g. invalid account):
 * nothing was or will be paid, so the held funds can be released.
 */
class PayoutRefusedException extends RuntimeException {}
