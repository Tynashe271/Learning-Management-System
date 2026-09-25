<?php

namespace App\Services;

use RuntimeException;

/** A sign-in failure that is safe to show the user; details go to the log instead. */
class SsoException extends RuntimeException {}
