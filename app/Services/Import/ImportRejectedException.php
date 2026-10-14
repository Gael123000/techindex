<?php

namespace App\Services\Import;

use RuntimeException;

/**
 * Import refusé avant toute écriture (message destiné à l'utilisateur).
 */
class ImportRejectedException extends RuntimeException {}
