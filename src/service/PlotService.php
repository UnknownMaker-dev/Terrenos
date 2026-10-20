<?php

declare(strict_types=1);

namespace Unknown\Terrenos\service;

use InvalidArgumentException;
use pocketmine\player\Player;
use pocketmine\world\World;
use Unknown\Forja\region\Cuboid;
use Unknown\Terrenos\model\Actor;
use Unknown\Terrenos\model\AreaRules;
use Unknown\Terrenos\model\Flag;
use Unknown\Terrenos\model\LeasePhase;
use Unknown\Terrenos\model\Plot;
use Unknown\Terrenos\model\PlotConflictException;
use Unknown\Terrenos\model\Role;
use Unknown\Terrenos\Terrenos;
use Unknown\Terrenos\ui\Format;
use function abs;
use function count;
use function mb_strlen;
use function strtolower;
use function time;
use function trim;

/**
 * Operações que alteram terrenos (criar, apagar, comprar, renovar, liberar, confiáveis,
 * bandeiras). Cada uma revalida tudo a partir do id — quem chama pode estar respondendo
 * um formulário aberto há minutos — e grava os dados ao terminar.
 *
 * Falhas lançam {@see ActionFailed} com a mensagem para o jogador.
 */
final class PlotService {

	public function __construct(
		private Terrenos $plugin
	) {}

	// ---------------- administração ----------------

	/**
	 * @param bool $expandFullHeight estende do fundo ao topo do mundo
	 * @throws ActionFailed
	 */
	public function create(string $rawId, ?string $name, Cuboid $area, bool $expandFullHeight) : Plot {
		$id = Plot::normalizeId($rawId);
		if($id === null){
			throw new ActionFailed("§cId inválido: §f{$rawId}§c. Use de 1 a 32 letras minúsculas, números, - ou _.");
		}
		$registry = $this->plugin->getRegistry();
		if($registry->get($id) !== null){
			throw new ActionFailed("§cJá existe um terreno com o id §f{$id}§c.");
		}

		$worldManager = $this->plugin->getServer()->getWorldManager();
		$world = $worldManager->getWorldByName($area->getWorld());
		if($world === null && !$worldManager->isWorldGenerated($area->getWorld())){
			throw new ActionFailed("§cO mundo §f{$area->getWorld()}§c não existe.");
		}
		$minY = $world?->getMinY() ?? World::Y_MIN;
		$maxY = ($world?->getMaxY() ?? World::Y_MAX) - 1;
		try{
			$area = AreaRules::prepare($area, $expandFullHeight, $minY, $maxY);
		}catch(InvalidArgumentException $e){
			throw new ActionFailed("§c" . $e->getMessage() . ".");
		}

		try{
			$plot = new Plot($id, $name !== null && trim($name) !== "" ? trim($name) : $id, $area, $this->plugin->getSettings()->defaultPrice, time(), $this->plugin->getSettings()->defaultFlags);
		}catch(InvalidArgumentException $e){
			throw new ActionFailed("§c" . $e->getMessage() . ".");
		}

		try{
			$registry->add($plot);
		}catch(PlotConflictException $e){
			$other = $e->getConflicting();
			throw new ActionFailed($other !== null
				? "§cA área invade o terreno §f{$other->getName()} §8({$other->getId()})§c: " . Format::area($other) . ". Terrenos não podem se sobrepor."
				: "§c" . $e->getMessage());
		}
		$this->plugin->persist();
		$this->plugin->getLogger()->info("Terreno '$id' criado: " . Format::area($plot) . ".");
		return $plot;
	}

	/** @throws ActionFailed */
	public function delete(string $id) : Plot {
		$plot = $this->plugin->getRegistry()->remove($id) ?? throw new ActionFailed("§cTerreno não encontrado: §f$id");
		$this->plugin->getWiper()->cancel($plot->getId());
		$this->plugin->persist();
		$this->plugin->getLogger()->info("Terreno '{$plot->getId()}' apagado.");
		return $plot;
	}

	/** @throws ActionFailed */
	public function setPrice(string $id, float $price) : Plot {
		$plot = $this->require($id);
		try{
			$plot->setPrice($price);
		}catch(InvalidArgumentException){
			throw new ActionFailed("§cPreço inválido.");
		}
		$this->plugin->persist();
		return $plot;
	}

	/** @throws ActionFailed */
	public function rename(string $id, string $name) : Plot {
		$plot = $this->require($id);
		try{
			$plot->setName($name);
		}catch(InvalidArgumentException $e){
			throw new ActionFailed("§c" . $e->getMessage() . ".");
		}
		$this->plugin->persist();
		return $plot;
	}

	/**
	 * Define o dono sem cobrar (administração).
	 *
	 * @param int|null $seconds duração do aluguel; null = sem vencimento
	 * @throws ActionFailed
	 */
	public function assign(string $id, string $playerName, ?int $seconds) : Plot {
		$plot = $this->require($id);
		$playerName = trim($playerName);
		if($playerName === "" || mb_strlen($playerName) > 32){
			throw new ActionFailed("§cNome de jogador inválido.");
		}
		$online = $this->plugin->getServer()->getPlayerExact($playerName);
		$display = $online?->getName() ?? $playerName;
		$xuid = $online?->getXuid() ?? "";
		if($plot->getOwner() !== strtolower($display)){
			$plot->resetFlags($this->plugin->getSettings()->defaultFlags);
		}
		$plot->assignOwner($display, $xuid, $seconds === null ? null : time() + $seconds);
		$plot->setWipePending(false); // limpeza esperando o mundo carregar não apaga mais nada do novo dono
		$this->plugin->persist();
		$this->plugin->getLogger()->info("Terreno '{$plot->getId()}' entregue a $display por um administrador.");
		return $plot;
	}

	/**
	 * Devolve o terreno à venda (sem dono, sem confiáveis, bandeiras padrão) e, se
	 * configurado, esvazia os contêineres.
	 */
	public function release(Plot $plot, string $reason) : void {
		$previous = $plot->hasOwner() ? $plot->getOwnerDisplay() : null;
		$plot->release($this->plugin->getSettings()->defaultFlags);
		if($this->plugin->getSettings()->clearContainersOnRelease){
			$this->plugin->getWiper()->wipe($plot);
		}
		$this->plugin->persist();
		$this->plugin->getLogger()->info("Terreno '{$plot->getId()}' liberado ($reason)" . ($previous !== null ? "; dono anterior: $previous" : "") . ".");
		if($previous !== null){
			$this->plugin->getServer()->getPlayerExact($previous)?->sendMessage($this->plugin->message("§cO terreno §f{$plot->getName()}§c não é mais seu ($reason)."));
		}
	}

	// ---------------- jogadores ----------------

	/**
	 * @param float $expectedPrice preço mostrado ao jogador (se mudou, a compra é cancelada)
	 * @throws ActionFailed
	 */
	public function buy(Player $player, string $id, float $expectedPrice) : Plot {
		$plot = $this->require($id);
		$now = time();
		$rules = $this->plugin->getGuard()->getRules();
		$phase = $rules->phase($plot, $now);
		if($phase === LeasePhase::EXPIRED){
			$this->release($plot, "aluguel vencido");
		}elseif($phase !== LeasePhase::VACANT){
			throw new ActionFailed($plot->isOwner($this->plugin->actorOf($player))
				? "§cEste terreno já é seu. Use §f/terreno renovar {$plot->getId()}§c para renovar."
				: "§cAlguém comprou este terreno antes de você.");
		}
		$this->checkPrice($plot, $expectedPrice);

		$limit = $this->plugin->getSettings()->maxPerPlayer;
		if($limit > 0 && count($this->heldBy($this->plugin->actorOf($player), $now)) >= $limit){
			throw new ActionFailed("§cVocê já tem o máximo de §f{$limit}§c terreno(s).");
		}

		$this->charge($player, $plot->getPrice(), "a compra");
		$plot->resetFlags($this->plugin->getSettings()->defaultFlags);
		$plot->assignOwner($player->getName(), $player->getXuid(), $rules->purchaseExpiry($now));
		$plot->setWipePending(false); // limpeza esperando o mundo carregar não apaga mais nada do novo dono
		$this->plugin->persist();
		$this->plugin->getLogger()->info("Terreno '{$plot->getId()}' comprado por {$player->getName()}.");
		return $plot;
	}

	/**
	 * @throws ActionFailed
	 */
	public function renew(Player $player, string $id, float $expectedPrice) : Plot {
		$plot = $this->require($id);
		$now = time();
		$this->requireOwner($player, $plot, $now);
		if($plot->getExpiresAt() === null){
			throw new ActionFailed("§eEste terreno não tem vencimento; não precisa renovar.");
		}
		$rules = $this->plugin->getGuard()->getRules();
		$next = $rules->renewalExpiry($plot, $now);
		if($next === null){
			throw new ActionFailed("§cVocê já pagou o máximo permitido à frente (§f" . Format::duration($rules->getMaxAheadSeconds()) . "§c). Renove mais perto do vencimento.");
		}
		$this->checkPrice($plot, $expectedPrice);
		$this->charge($player, $plot->getPrice(), "a renovação");
		$plot->setExpiresAt($next);
		$this->plugin->persist();
		return $plot;
	}

	/** @throws ActionFailed */
	public function abandon(Player $player, string $id) : Plot {
		$plot = $this->require($id);
		$this->requireOwner($player, $plot, time());
		$this->release($plot, "abandonado pelo dono");
		return $plot;
	}

	/**
	 * @return string nome do jogador como foi guardado
	 * @throws ActionFailed
	 */
	public function addMember(Player $owner, string $id, string $name) : string {
		$plot = $this->require($id);
		$this->requireManager($owner, $plot);
		$name = trim($name);
		if($name === "" || mb_strlen($name) > 32){
			throw new ActionFailed("§cNome de jogador inválido.");
		}
		$max = $this->plugin->getSettings()->maxMembers;
		if($max > 0 && $plot->memberCount() >= $max){
			throw new ActionFailed("§cEste terreno já tem o máximo de §f{$max}§c confiáveis.");
		}
		$target = $this->plugin->getServer()->getPlayerExact($name);
		$display = $target?->getName() ?? $name;
		if($plot->getOwner() === strtolower($display)){
			throw new ActionFailed("§cO dono já tem acesso total.");
		}
		if(!$plot->addMember($display, $target?->getXuid() ?? "")){
			throw new ActionFailed("§f{$display}§c já é confiável neste terreno.");
		}
		$this->plugin->persist();
		$target?->sendMessage($this->plugin->message("§aVocê agora é confiável no terreno §f{$plot->getName()}§a."));
		return $display;
	}

	/** @throws ActionFailed */
	public function removeMember(Player $owner, string $id, string $name) : void {
		$plot = $this->require($id);
		$this->requireManager($owner, $plot);
		if(!$plot->removeMember($name)){
			throw new ActionFailed("§f{$name}§c não é confiável neste terreno.");
		}
		$this->plugin->persist();
	}

	/** @throws ActionFailed */
	public function setFlag(Player $owner, string $id, Flag $flag, bool $value) : Plot {
		$plot = $this->require($id);
		$this->requireManager($owner, $plot);
		$plot->setFlag($flag, $value);
		$this->plugin->persist();
		return $plot;
	}

	/**
	 * @param array<string, bool> $values valor da Flag => ligada
	 * @throws ActionFailed
	 */
	public function setFlags(Player $owner, string $id, array $values) : Plot {
		$plot = $this->require($id);
		$this->requireManager($owner, $plot);
		foreach(Flag::cases() as $flag){
			if(isset($values[$flag->value])){
				$plot->setFlag($flag, $values[$flag->value]);
			}
		}
		$this->plugin->persist();
		return $plot;
	}

	/**
	 * Terrenos que o jogador tem agora (em dia, vencendo ou na carência).
	 *
	 * @return list<Plot>
	 */
	public function heldBy(Actor $actor, int $now) : array {
		$list = [];
		$rules = $this->plugin->getGuard()->getRules();
		foreach($this->plugin->getRegistry()->all() as $plot){
			if($plot->isOwner($actor) && $rules->phase($plot, $now)->isHeld()){
				$list[] = $plot;
			}
		}
		return $list;
	}

	/** True se o jogador pode gerenciar (dono com posse ou administrador). */
	public function canManage(Player $player, Plot $plot) : bool {
		return $player->hasPermission(Terrenos::PERMISSION_ADMIN)
			|| $this->plugin->getGuard()->roleOf($plot, $this->plugin->actorOf($player), time()) === Role::OWNER;
	}

	/** @throws ActionFailed */
	public function require(string $id) : Plot {
		return $this->plugin->getRegistry()->get($id) ?? throw new ActionFailed("§cTerreno não encontrado: §f$id");
	}

	/** @throws ActionFailed */
	private function requireOwner(Player $player, Plot $plot, int $now) : void {
		if($this->plugin->getGuard()->roleOf($plot, $this->plugin->actorOf($player), $now) !== Role::OWNER){
			throw new ActionFailed("§cVocê não é o dono do terreno §f{$plot->getName()}§c.");
		}
	}

	/** @throws ActionFailed */
	private function requireManager(Player $player, Plot $plot) : void {
		if(!$this->canManage($player, $plot)){
			throw new ActionFailed("§cVocê não é o dono do terreno §f{$plot->getName()}§c.");
		}
		if(!$plot->hasOwner()){
			throw new ActionFailed("§cO terreno §f{$plot->getName()}§c está à venda; não há o que gerenciar.");
		}
	}

	/** @throws ActionFailed */
	private function checkPrice(Plot $plot, float $expectedPrice) : void {
		if(abs($plot->getPrice() - $expectedPrice) > 0.0001){
			throw new ActionFailed("§cO preço deste terreno mudou para §e" . Format::price($this->plugin->getEconomy(), $plot->getPrice()) . "§c. Confira e tente de novo.");
		}
	}

	/** @throws ActionFailed */
	private function charge(Player $player, float $amount, string $what) : void {
		if($amount <= 0){
			return;
		}
		$economy = $this->plugin->getEconomy();
		if(!$economy->isAvailable()){
			throw new ActionFailed("§cA economia do servidor está indisponível; não é possível concluir $what agora.");
		}
		if(!$economy->take($player, $amount)){
			throw new ActionFailed("§cSaldo insuficiente: $what custa §e" . $economy->format($amount) . "§c e você tem §e" . $economy->format($economy->balance($player)) . "§c.");
		}
	}
}
