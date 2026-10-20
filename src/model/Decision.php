<?php

declare(strict_types=1);

namespace Unknown\Terrenos\model;

/** Resultado de uma consulta ao {@see Guard}. */
final class Decision {

	private function __construct(
		private bool $allowed,
		private ?Plot $plot,
		private ?Role $role
	) {}

	public static function allow(?Plot $plot, ?Role $role) : self {
		return new self(true, $plot, $role);
	}

	public static function deny(Plot $plot, Role $role) : self {
		return new self(false, $plot, $role);
	}

	public function isAllowed() : bool {
		return $this->allowed;
	}

	/** O terreno da posição consultada (null = fora de qualquer terreno). */
	public function getPlot() : ?Plot {
		return $this->plot;
	}

	/** Papel do jogador nesse terreno (null fora de terreno ou com bypass). */
	public function getRole() : ?Role {
		return $this->role;
	}
}
