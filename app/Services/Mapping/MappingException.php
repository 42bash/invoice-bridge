<?php

namespace App\Services\Mapping;

use RuntimeException;

/** The invoice cannot become a valid French e-invoice; the message tells the user what to fix. */
class MappingException extends RuntimeException {}
