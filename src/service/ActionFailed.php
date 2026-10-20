<?php

declare(strict_types=1);

namespace Unknown\Terrenos\service;

use RuntimeException;

/**
 * Operação recusada; a mensagem (pt-BR, com cores) é mostrada a quem pediu.
 */
final class ActionFailed extends RuntimeException {}
