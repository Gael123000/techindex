<?php

namespace App\Services\Updates;

use RuntimeException;

/**
 * Erreur du canal de mise à jour ; le message est destiné à l'administrateur.
 */
class UpdateException extends RuntimeException {}
