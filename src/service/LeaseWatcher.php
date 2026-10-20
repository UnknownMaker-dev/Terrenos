<?php

declare(strict_types=1);

namespace Unknown\Terrenos\service;

use pocketmine\player\Player;
use Unknown\Terrenos\model\LeasePhase;
use Unknown\Terrenos\Terrenos;
use Unknown\Terrenos\ui\Format;
use function time;

/**
 * Verificação periódica do aluguel: libera os terrenos cuja carência acabou e lembra
 * os donos online de aluguel perto de vencer ou já vencido (em carência).
 */
final class LeaseWatcher {

	public function __construct(
		private Terrenos $plugin
	) {}

	public function tick() : void {
		$now = time();
		$rules = $this->plugin->getGuard()->getRules();
		foreach($this->plugin->getRegistry()->all() as $plot){
			if($rules->phase($plot, $now) === LeasePhase::EXPIRED){
				$this->plugin->getService()->release($plot, "aluguel vencido e carência esgotada");
			}
		}

		$interval = $this->plugin->getSettings()->reminderInterval;
		foreach($this->plugin->getServer()->getOnlinePlayers() as $player){
			$last = $this->plugin->getNotices()->lastReminder($player->getName());
			if($last === null || $now - $last >= $interval){
				$this->remind($player, $now);
			}
		}
	}

	/**
	 * Envia ao jogador os avisos dos terrenos dele que estão vencendo ou em carência.
	 *
	 * @return int quantidade de avisos enviados
	 */
	public function remind(Player $player, int $now) : int {
		$rules = $this->plugin->getGuard()->getRules();
		$sent = 0;
		foreach($this->plugin->getService()->heldBy($this->plugin->actorOf($player), $now) as $plot){
			$phase = $rules->phase($plot, $now);
			$expires = (int) $plot->getExpiresAt();
			if($phase === LeasePhase::ENDING){
				$player->sendMessage($this->plugin->message("§eO aluguel do terreno §f{$plot->getName()}§e vence em §f" . Format::duration($expires - $now) . " §7(" . Format::date($expires) . ")§e. Renove com §f/terreno renovar {$plot->getId()}§e."));
				$sent++;
			}elseif($phase === LeasePhase::GRACE){
				$releaseAt = (int) $rules->releaseAt($plot);
				$player->sendMessage($this->plugin->message("§cO aluguel do terreno §f{$plot->getName()}§c venceu! Ele será liberado em §f" . Format::duration($releaseAt - $now) . " §7(" . Format::date($releaseAt) . ")§c se não for renovado: §f/terreno renovar {$plot->getId()}"));
				$sent++;
			}
		}
		$this->plugin->getNotices()->markReminder($player->getName(), $now);
		return $sent;
	}
}
