<?php

declare(strict_types=1);

namespace Unknown\Terrenos\model;

use function strtolower;

/**
 * Quem está agindo, sem depender de um Player (facilita testar as regras).
 */
final class Actor {

	private string $name;

	/**
	 * @param string $name nome do jogador (qualquer caixa; é guardado em minúsculas)
	 * @param string $xuid xuid da conta Xbox, ou "" se desconhecido
	 * @param bool $bypass true = ignora a proteção (permissão terrenos.bypass)
	 */
	public function __construct(
		string $name,
		private string $xuid = "",
		private bool $bypass = false
	) {
		$this->name = strtolower($name);
	}

	public function getName() : string {
		return $this->name;
	}

	public function getXuid() : string {
		return $this->xuid;
	}

	public function hasBypass() : bool {
		return $this->bypass;
	}
}
