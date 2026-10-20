<?php

declare(strict_types=1);

namespace Unknown\Terrenos\command;

use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use Unknown\Forja\command\Args;
use Unknown\Forja\command\BaseCommand;
use Unknown\Forja\command\CommandFeedback;
use Unknown\Forja\command\UsageException;
use Unknown\Forja\Forja;
use Unknown\Forja\region\Cuboid;
use Unknown\Forja\util\Duration;
use Unknown\Terrenos\model\Flag;
use Unknown\Terrenos\model\LeasePhase;
use Unknown\Terrenos\model\Plot;
use Unknown\Terrenos\model\Role;
use Unknown\Terrenos\service\ActionFailed;
use Unknown\Terrenos\Terrenos;
use Unknown\Terrenos\ui\Format;
use function array_map;
use function ceil;
use function count;
use function implode;
use function in_array;
use function max;
use function min;
use function mb_strtolower;
use function preg_match;
use function time;

/**
 * /terreno — sem argumentos abre o menu do terreno onde o jogador está;
 * /terreno <id> abre o menu daquele terreno.
 */
final class PlotCommand extends BaseCommand {

	private const PAGE_SIZE = 10;

	public function __construct(
		private Terrenos $plugin
	) {
		parent::__construct($plugin, "terreno", "Terrenos alugáveis", ["terrenos"], Terrenos::PERMISSION_USE . ";" . Terrenos::PERMISSION_ADMIN);
		$use = Terrenos::PERMISSION_USE;
		$admin = Terrenos::PERMISSION_ADMIN;

		// jogadores
		$this->sub("info", "Mostra os dados de um terreno", "[id]", [], null, false, $this->info(...));
		$this->sub("comprar", "Compra o terreno onde você está", "[id]", ["buy"], $use, true, $this->buy(...));
		$this->sub("renovar", "Renova o aluguel do seu terreno", "[id]", ["renew"], $use, true, $this->renew(...));
		$this->sub("meus", "Lista os seus terrenos", "", ["mine"], $use, true, $this->mine(...));
		$this->sub("confiar", "Dá acesso total a um jogador", "<jogador> [id]", ["trust"], $use, true, $this->trust(...));
		$this->sub("desconfiar", "Tira o acesso de um jogador", "<jogador> [id]", ["untrust"], $use, true, $this->untrust(...));
		$this->sub("bandeira", "Libera ou bloqueia algo para visitantes", "<" . implode("|", array_map(static fn(Flag $f) => $f->value, Flag::cases())) . "> <sim|não> [id]", ["flag"], $use, true, $this->flag(...));
		$this->sub("abandonar", "Desiste do seu terreno (sem reembolso)", "[id]", ["abandon"], $use, true, $this->abandon(...));

		// administração
		$this->sub("pos1", "Marca o primeiro canto na sua posição", "", [], $admin, true, fn(CommandSender $s, Args $a) => $this->mark($s, 1));
		$this->sub("pos2", "Marca o segundo canto na sua posição", "", [], $admin, true, fn(CommandSender $s, Args $a) => $this->mark($s, 2));
		$this->sub("criar", "Cria um terreno (seleção ou coordenadas)", "<id> [<mundo> <x1> <z1> <x2> <z2> [y1 y2]]", ["create"], $admin, false, $this->create(...));
		$this->sub("preco", "Define o preço do aluguel", "<id> <valor>", ["setprice"], $admin, false, $this->setPrice(...));
		$this->sub("nome", "Muda o nome exibido do terreno", "<id> <nome>", ["rename"], $admin, false, $this->rename(...));
		$this->sub("dono", "Entrega o terreno a um jogador sem cobrar", "<id> <jogador> [duração|permanente]", ["setowner"], $admin, false, $this->setOwner(...));
		$this->sub("liberar", "Tira o dono e põe o terreno à venda", "<id>", ["release"], $admin, false, $this->release(...));
		$this->sub("apagar", "Apaga um terreno", "<id>", ["delete"], $admin, false, $this->delete(...));
		$this->sub("lista", "Lista todos os terrenos", "[página]", ["list"], $admin, false, $this->list(...));
		$this->sub("recarregar", "Relê o config.yml", "", ["reload"], $admin, false, $this->reload(...));
	}

	/**
	 * @param list<string> $aliases
	 * @param \Closure(CommandSender, Args) : void $handler
	 */
	private function sub(string $name, string $description, string $usage, array $aliases, ?string $permission, bool $playerOnly, \Closure $handler) : void {
		$this->registerSubCommand(new ClosureSubCommand($name, $description, $usage, $aliases, $permission, $playerOnly, function(CommandSender $sender, Args $args) use ($handler) : void {
			try{
				$handler($sender, $args);
			}catch(ActionFailed $e){
				throw new CommandFeedback($this->plugin->message($e->getMessage()));
			}
		}));
	}

	protected function onDefault(CommandSender $sender) : void {
		if(!$sender instanceof Player){
			$this->sendHelp($sender);
			return;
		}
		$pos = $sender->getPosition();
		$plot = $this->plugin->getRegistry()->at($pos->getWorld()->getFolderName(), $pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ());
		if($plot !== null){
			$this->plugin->getMenus()->open($sender, $plot);
			return;
		}
		$mine = $this->plugin->getService()->heldBy($this->plugin->actorOf($sender), time());
		if(count($mine) > 0){
			$this->plugin->getMenus()->openList($sender, $mine, "Meus terrenos");
			return;
		}
		$sender->sendMessage($this->plugin->message("§eVocê não está em um terreno. Use §f/terreno <id>§e ou §f/terreno ajuda§e."));
	}

	/** `/terreno <id>` abre o menu daquele terreno. */
	protected function onUnknownSubCommand(CommandSender $sender, Args $args) : void {
		$plot = $sender instanceof Player && $args->count() === 1 ? $this->plugin->getRegistry()->get($args->string(0)) : null;
		if($plot === null || !$sender instanceof Player){
			parent::onUnknownSubCommand($sender, $args);
			return;
		}
		$this->plugin->getMenus()->open($sender, $plot);
	}

	// ---------------- jogadores ----------------

	private function info(CommandSender $sender, Args $args) : void {
		$plot = $this->resolve($sender, $args, 0, false);
		$sender->sendMessage(Format::info($plot, $this->plugin->getEconomy(), $this->plugin->getGuard()->getRules(), time()));
	}

	private function buy(CommandSender $sender, Args $args) : void {
		$player = $this->player($sender);
		$plot = $this->resolve($sender, $args, 0, false);
		$phase = $this->plugin->getGuard()->getRules()->phase($plot, time());
		if($phase !== LeasePhase::VACANT && $phase !== LeasePhase::EXPIRED){
			throw new ActionFailed("§cO terreno §f{$plot->getName()}§c não está à venda.");
		}
		if($plot->getPrice() > 0 && !$this->plugin->getEconomy()->isAvailable()){
			throw new ActionFailed("§cA economia do servidor está indisponível; não é possível comprar agora.");
		}
		$this->plugin->getMenus()->confirmBuy($player, $plot->getId(), $plot->getPrice());
	}

	private function renew(CommandSender $sender, Args $args) : void {
		$player = $this->player($sender);
		$plot = $this->resolve($sender, $args, 0, true);
		if($this->plugin->getGuard()->roleOf($plot, $this->plugin->actorOf($player), time()) !== Role::OWNER){
			throw new ActionFailed("§cVocê não é o dono do terreno §f{$plot->getName()}§c.");
		}
		if($plot->getExpiresAt() === null){
			throw new ActionFailed("§eEste terreno não tem vencimento; não precisa renovar.");
		}
		$this->plugin->getMenus()->confirmRenew($player, $plot->getId(), $plot->getPrice());
	}

	private function mine(CommandSender $sender, Args $args) : void {
		$player = $this->player($sender);
		$this->plugin->getMenus()->openList($player, $this->plugin->getService()->heldBy($this->plugin->actorOf($player), time()), "Meus terrenos");
	}

	private function trust(CommandSender $sender, Args $args) : void {
		$player = $this->player($sender);
		$name = $args->string(0);
		$plot = $this->resolve($sender, $args, 1, true);
		$added = $this->plugin->getService()->addMember($player, $plot->getId(), $name);
		$sender->sendMessage($this->plugin->message("§f{$added}§a agora é confiável no terreno §f{$plot->getName()}§a."));
	}

	private function untrust(CommandSender $sender, Args $args) : void {
		$player = $this->player($sender);
		$name = $args->string(0);
		$plot = $this->resolve($sender, $args, 1, true);
		$this->plugin->getService()->removeMember($player, $plot->getId(), $name);
		$sender->sendMessage($this->plugin->message("§f{$name}§e não é mais confiável no terreno §f{$plot->getName()}§e."));
	}

	private function flag(CommandSender $sender, Args $args) : void {
		$player = $this->player($sender);
		$flag = Flag::parse($args->string(0)) ?? throw new CommandFeedback($this->plugin->message("§cBandeira desconhecida. Use: §f" . implode(", ", array_map(static fn(Flag $f) => $f->value, Flag::cases()))));
		$value = $args->bool(1);
		$plot = $this->resolve($sender, $args, 2, true);
		$this->plugin->getService()->setFlag($player, $plot->getId(), $flag, $value);
		$sender->sendMessage($this->plugin->message("§a{$flag->label()}: " . ($value ? "§aliberado" : "§cbloqueado") . "§a para visitantes em §f{$plot->getName()}§a."));
	}

	private function abandon(CommandSender $sender, Args $args) : void {
		$player = $this->player($sender);
		$plot = $this->resolve($sender, $args, 0, true);
		if(!$this->plugin->getService()->canManage($player, $plot) || !$plot->hasOwner()){
			throw new ActionFailed("§cVocê não é o dono deste terreno.");
		}
		$this->plugin->getMenus()->confirmAbandon($player, $plot->getId());
	}

	// ---------------- administração ----------------

	private function mark(CommandSender $sender, int $corner) : void {
		$player = $this->player($sender);
		$pos = $player->getPosition();
		$selections = Forja::getInstance()->getSelections();
		$corner === 1 ? $selections->setPos1($player, $pos) : $selections->setPos2($player, $pos);
		$sender->sendMessage($this->plugin->message("§aPosição $corner marcada em §f{$pos->getFloorX()}, {$pos->getFloorY()}, {$pos->getFloorZ()}§a ({$pos->getWorld()->getFolderName()})."));
	}

	private function create(CommandSender $sender, Args $args) : void {
		$id = $args->string(0);
		$fullHeight = $this->plugin->getSettings()->fullHeight;

		if($args->count() === 1){
			if(!$sender instanceof Player){
				throw new CommandFeedback($this->plugin->message("§cNo console informe as coordenadas: §f/terreno criar <id> <mundo> <x1> <z1> <x2> <z2> [y1 y2]"));
			}
			$area = Forja::getInstance()->getSelections()->getCuboid($sender)
				?? throw new CommandFeedback($this->plugin->message("§cMarque os dois cantos com §f/terreno pos1§c e §f/terreno pos2§c (no mesmo mundo)."));
		}else{
			if($args->count() !== 6 && $args->count() !== 8){
				throw new UsageException("número de argumentos");
			}
			$world = $args->string(1);
			$x1 = $args->int(2);
			$z1 = $args->int(3);
			$x2 = $args->int(4);
			$z2 = $args->int(5);
			if($args->count() === 8){
				$area = new Cuboid($world, $x1, $args->int(6), $z1, $x2, $args->int(7), $z2);
				$fullHeight = false;
			}else{
				$area = new Cuboid($world, $x1, 0, $z1, $x2, 0, $z2);
				$fullHeight = true;
			}
		}

		$plot = $this->plugin->getService()->create($id, null, $area, $fullHeight);
		$sender->sendMessage($this->plugin->message("§aTerreno §f{$plot->getId()}§a criado: " . Format::area($plot) . " §7(" . Format::size($plot) . "), preço §e" . Format::price($this->plugin->getEconomy(), $plot->getPrice()) . "§a."));
		if($sender instanceof Player){
			Forja::getInstance()->getSelections()->clear($sender);
		}
	}

	private function setPrice(CommandSender $sender, Args $args) : void {
		$id = $args->string(0);
		$price = $args->float(1, 0.0, 1e15);
		$plot = $this->plugin->getService()->setPrice($id, $price);
		$sender->sendMessage($this->plugin->message("§aPreço do terreno §f{$plot->getId()}§a: §e" . Format::price($this->plugin->getEconomy(), $plot->getPrice()) . "§a."));
	}

	private function rename(CommandSender $sender, Args $args) : void {
		$plot = $this->plugin->getService()->rename($args->string(0), $args->rest(1));
		$sender->sendMessage($this->plugin->message("§aTerreno §f{$plot->getId()}§a agora se chama §f{$plot->getName()}§a."));
	}

	private function setOwner(CommandSender $sender, Args $args) : void {
		$id = $args->string(0);
		$name = $args->string(1);
		$raw = $args->optString(2, "");
		if($raw === ""){
			$seconds = $this->plugin->getGuard()->getRules()->getRentSeconds();
		}elseif(in_array(mb_strtolower($raw, "UTF-8"), ["permanente", "permanent", "0"], true)){
			$seconds = null;
		}else{
			$seconds = Duration::parse($raw) ?? throw new CommandFeedback($this->plugin->message("§cDuração inválida: §f{$raw}§c (ex.: 30d, 12h, permanente)."));
		}
		$plot = $this->plugin->getService()->assign($id, $name, $seconds);
		$sender->sendMessage($this->plugin->message("§aTerreno §f{$plot->getId()}§a entregue a §f{$plot->getOwnerDisplay()}§a: " . Format::lease($plot, $this->plugin->getGuard()->getRules(), time())));
	}

	private function release(CommandSender $sender, Args $args) : void {
		$plot = $this->plugin->getService()->require($args->string(0));
		if(!$plot->hasOwner()){
			throw new ActionFailed("§eO terreno §f{$plot->getId()}§e já está à venda.");
		}
		$this->plugin->getService()->release($plot, "liberado por um administrador");
		$sender->sendMessage($this->plugin->message("§eTerreno §f{$plot->getId()}§e liberado e à venda."));
	}

	private function delete(CommandSender $sender, Args $args) : void {
		$plot = $this->plugin->getService()->delete($args->string(0));
		$sender->sendMessage($this->plugin->message("§eTerreno §f{$plot->getId()}§e apagado."));
	}

	private function list(CommandSender $sender, Args $args) : void {
		$plots = $this->plugin->getRegistry()->all();
		if(count($plots) === 0){
			$sender->sendMessage($this->plugin->message("§eNenhum terreno criado."));
			return;
		}
		$pages = (int) ceil(count($plots) / self::PAGE_SIZE);
		$page = max(1, min($pages, $args->optInt(0, 1, 1)));
		$now = time();
		$rules = $this->plugin->getGuard()->getRules();
		$economy = $this->plugin->getEconomy();
		$sender->sendMessage($this->plugin->message("§6Terrenos (" . count($plots) . ") — página $page/$pages:"));
		for($i = ($page - 1) * self::PAGE_SIZE; $i < min(count($plots), $page * self::PAGE_SIZE); $i++){
			$plot = $plots[$i];
			$owner = $plot->hasOwner() ? "§f" . $plot->getOwnerDisplay() : "§8—";
			$sender->sendMessage("§e{$plot->getId()} §7| $owner §7| " . Format::lease($plot, $rules, $now) . " §7| §e" . Format::price($economy, $plot->getPrice()) . " §7| {$plot->getWorld()} " . Format::size($plot));
		}
	}

	private function reload(CommandSender $sender, Args $args) : void {
		$this->plugin->reloadSettings();
		$sender->sendMessage($this->plugin->message("§aConfiguração recarregada."));
	}

	// ---------------- auxiliares ----------------

	private function player(CommandSender $sender) : Player {
		return $sender instanceof Player ? $sender : throw new CommandFeedback(BaseCommand::MESSAGE_PLAYER_ONLY);
	}

	/**
	 * Terreno pelo id informado em `$index`; sem id, o terreno onde o jogador está e,
	 * se `$preferOwned`, o único terreno que ele tem.
	 *
	 * @throws ActionFailed
	 */
	private function resolve(CommandSender $sender, Args $args, int $index, bool $preferOwned) : Plot {
		if($args->has($index)){
			$id = $args->string($index);
			if(preg_match(Plot::ID_PATTERN, mb_strtolower($id, "UTF-8")) !== 1){
				throw new ActionFailed("§cId inválido: §f$id");
			}
			return $this->plugin->getService()->require($id);
		}
		if(!$sender instanceof Player){
			throw new UsageException("id obrigatório no console");
		}
		$pos = $sender->getPosition();
		$here = $this->plugin->getRegistry()->at($pos->getWorld()->getFolderName(), $pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ());
		if($here !== null){
			return $here;
		}
		if($preferOwned){
			$mine = $this->plugin->getService()->heldBy($this->plugin->actorOf($sender), time());
			if(count($mine) === 1){
				return $mine[0];
			}
		}
		throw new ActionFailed("§cFique dentro do terreno ou informe o id.");
	}
}
