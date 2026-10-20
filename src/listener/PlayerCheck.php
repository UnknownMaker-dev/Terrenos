<?php

declare(strict_types=1);

namespace Unknown\Terrenos\listener;

use pocketmine\player\Player;
use pocketmine\world\Position;
use Unknown\Terrenos\model\Action;
use Unknown\Terrenos\model\Decision;
use Unknown\Terrenos\Terrenos;
use function time;

/**
 * Ponte entre os eventos (Player + Position) e o {@see \Unknown\Terrenos\model\Guard},
 * que é quem decide. Também envia o aviso de "não pode" com intervalo mínimo.
 */
final class PlayerCheck {

	public function __construct(
		private Terrenos $plugin
	) {}

	/**
	 * @param bool $notify avisar o jogador se for negado
	 */
	public function allows(Player $player, Action $action, Position $pos, bool $notify = true) : bool {
		if(!$pos->isValid()){
			return true;
		}
		$decision = $this->plugin->getGuard()->decide(
			$this->plugin->actorOf($player),
			$action,
			$pos->getWorld()->getFolderName(),
			$pos->getFloorX(),
			$pos->getFloorY(),
			$pos->getFloorZ(),
			time()
		);
		if(!$decision->isAllowed() && $notify){
			$this->notify($player, $action, $decision);
		}
		return $decision->isAllowed();
	}

	public function notify(Player $player, Action $action, Decision $decision) : void {
		$plot = $decision->getPlot();
		if($plot === null){
			return;
		}
		$message = $plot->hasOwner()
			? "§cVocê não pode " . $action->describe() . " no terreno de §f" . $plot->getOwnerDisplay() . "§c."
			: "§cTerreno à venda (§f{$plot->getName()}§c): protegido.";
		$this->plugin->getNotices()->denied($player, $message, $this->plugin->getSettings()->noticeCooldown);
	}
}
