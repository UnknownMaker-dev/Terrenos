<?php

declare(strict_types=1);

namespace Unknown\Terrenos\model;

use RuntimeException;

/** Id repetido ou área sobreposta ao criar um terreno. */
final class PlotConflictException extends RuntimeException {

	public function __construct(
		string $message,
		private ?Plot $conflicting
	) {
		parent::__construct($message);
	}

	/** O terreno invadido, se o problema for sobreposição. */
	public function getConflicting() : ?Plot {
		return $this->conflicting;
	}
}
