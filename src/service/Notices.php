<?php

declare(strict_types=1);

namespace Unknown\Terrenos\service;

use pocketmine\player\Player;
use function microtime;
use function strtolower;

/**
 * Estado por jogador para não repetir mensagens: o aviso de "não pode fazer isso aqui"
 * (que dispara a cada clique) e os lembretes de aluguel. Limpo quando o jogador sai.
 */
final class Notices {

	/** @var array<string, float> nome => microtime do último aviso de proteção */
	private array $lastDenied = [];
	/** @var array<string, int> nome => time() do último lembrete de aluguel */
	private array $lastReminder = [];

	/** Envia o aviso como tip, no máximo um a cada `$cooldown` segundos por jogador. */
	public function denied(Player $player, string $message, float $cooldown) : void {
		$key = strtolower($player->getName());
		$now = microtime(true);
		if(isset($this->lastDenied[$key]) && $now - $this->lastDenied[$key] < $cooldown){
			return;
		}
		$this->lastDenied[$key] = $now;
		$player->sendTip($message);
	}

	public function lastReminder(string $name) : ?int {
		return $this->lastReminder[strtolower($name)] ?? null;
	}

	public function markReminder(string $name, int $time) : void {
		$this->lastReminder[strtolower($name)] = $time;
	}

	public function forget(Player $player) : void {
		$key = strtolower($player->getName());
		unset($this->lastDenied[$key], $this->lastReminder[$key]);
	}
}
