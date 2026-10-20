<?php

declare(strict_types=1);

namespace Unknown\Terrenos\ui;

use Closure;
use pocketmine\player\Player;
use Unknown\Forja\form\CustomForm;
use Unknown\Forja\form\FormResponse;
use Unknown\Forja\form\MenuForm;
use Unknown\Forja\form\ModalForm;
use Unknown\Terrenos\model\Flag;
use Unknown\Terrenos\model\LeasePhase;
use Unknown\Terrenos\model\Plot;
use Unknown\Terrenos\model\Role;
use Unknown\Terrenos\service\ActionFailed;
use Unknown\Terrenos\Terrenos;
use function array_merge;
use function count;
use function strtolower;
use function time;
use function trim;

/**
 * Menus por formulário. Todo callback busca o terreno de novo pelo id e confere a
 * permissão outra vez: entre abrir o menu e responder, o terreno pode ter sido
 * apagado, vendido ou ter mudado de preço.
 */
final class PlotMenus {

	public function __construct(
		private Terrenos $plugin
	) {}

	/** Menu principal do terreno, conforme quem olha. */
	public function open(Player $player, Plot $plot) : void {
		$now = time();
		$guard = $this->plugin->getGuard();
		$phase = $guard->getRules()->phase($plot, $now);
		$role = $guard->roleOf($plot, $this->plugin->actorOf($player), $now);
		$id = $plot->getId();
		$economy = $this->plugin->getEconomy();
		$info = Format::info($plot, $economy, $guard->getRules(), $now);

		if($phase === LeasePhase::VACANT || $phase === LeasePhase::EXPIRED){
			$price = $plot->getPrice();
			$content = $info . "\n\n";
			$canPay = $price <= 0 || $economy->isAvailable();
			if($price > 0 && $economy->isAvailable()){
				$content .= "§7Seu saldo: §e" . $economy->format($economy->balance($player)) . "\n";
			}elseif(!$canPay){
				$content .= "§cA economia está indisponível: não é possível comprar agora.\n";
			}
			$form = new MenuForm("§l§2Terreno à venda", $content);
			if($canPay){
				$form->button("§2§lComprar\n§r§8" . Format::price($economy, $price) . " por " . Format::duration($guard->getRules()->getRentSeconds()), fn(Player $p) => $this->confirmBuy($p, $id, $price));
			}
			$this->addAdminButton($form, $player, $id);
			$player->sendForm($form->button("Fechar"));
			return;
		}

		if($role === Role::OWNER){
			$form = new MenuForm("§l§6Meu terreno", $info);
			if($plot->getExpiresAt() !== null){
				$price = $plot->getPrice();
				$form->button("§2Renovar\n§r§8+" . Format::duration($guard->getRules()->getRentSeconds()) . " por " . Format::price($economy, $price), fn(Player $p) => $this->confirmRenew($p, $id, $price));
			}
			$form->button("§9Confiáveis\n§r§8Quem tem acesso total", fn(Player $p) => $this->openMembers($p, $id));
			$form->button("§6Permissões públicas\n§r§8O que visitantes podem fazer", fn(Player $p) => $this->openFlags($p, $id));
			$form->button("§cAbandonar terreno", fn(Player $p) => $this->confirmAbandon($p, $id));
			$this->addAdminButton($form, $player, $id);
			$player->sendForm($form->button("Fechar"));
			return;
		}

		$form = new MenuForm("§l§6Terreno", $info . "\n\n§7Seu acesso: §f" . $role->label());
		$this->addAdminButton($form, $player, $id);
		$player->sendForm($form->button("Fechar"));
	}

	public function confirmBuy(Player $player, string $id, float $price) : void {
		$plot = $this->plugin->getRegistry()->get($id);
		if($plot === null){
			$player->sendMessage($this->plugin->message("§cEste terreno não existe mais."));
			return;
		}
		$text = "Comprar o terreno §f{$plot->getName()}§r por §e" . Format::price($this->plugin->getEconomy(), $price) . "§r?\n\nEle será seu por §f" . Format::duration($this->plugin->getGuard()->getRules()->getRentSeconds()) . "§r. Renove antes de vencer.";
		$player->sendForm((new ModalForm("Confirmar compra", $text, "Comprar", "Cancelar"))
			->onChoice(function(Player $p, bool $yes) use ($id, $price) : void {
				if(!$yes){
					return;
				}
				$this->attempt($p, function() use ($p, $id, $price) : void {
					$plot = $this->plugin->getService()->buy($p, $id, $price);
					$p->sendMessage($this->plugin->message("§aParabéns! O terreno §f{$plot->getName()}§a é seu até §f" . Format::date((int) $plot->getExpiresAt()) . "§a. Use §f/terreno§a dentro dele para gerenciar."));
				});
			}));
	}

	public function confirmRenew(Player $player, string $id, float $price) : void {
		$plot = $this->plugin->getRegistry()->get($id);
		if($plot === null){
			$player->sendMessage($this->plugin->message("§cEste terreno não existe mais."));
			return;
		}
		$text = "Renovar o terreno §f{$plot->getName()}§r por mais §f" . Format::duration($this->plugin->getGuard()->getRules()->getRentSeconds()) . "§r pagando §e" . Format::price($this->plugin->getEconomy(), $price) . "§r?";
		$player->sendForm((new ModalForm("Confirmar renovação", $text, "Renovar", "Cancelar"))
			->onChoice(function(Player $p, bool $yes) use ($id, $price) : void {
				if(!$yes){
					return;
				}
				$this->attempt($p, function() use ($p, $id, $price) : void {
					$plot = $this->plugin->getService()->renew($p, $id, $price);
					$p->sendMessage($this->plugin->message("§aAluguel renovado! O terreno §f{$plot->getName()}§a agora vence em §f" . Format::date((int) $plot->getExpiresAt()) . "§a."));
				});
			}));
	}

	public function confirmAbandon(Player $player, string $id) : void {
		$player->sendForm((new ModalForm("Abandonar terreno", "Tem certeza? O terreno volta a ficar à venda, os confiáveis perdem o acesso e §cnão há reembolso§r.", "Abandonar", "Cancelar"))
			->onChoice(function(Player $p, bool $yes) use ($id) : void {
				if($yes){
					$this->attempt($p, function() use ($p, $id) : void {
						$plot = $this->plugin->getService()->abandon($p, $id);
						$p->sendMessage($this->plugin->message("§eVocê abandonou o terreno §f{$plot->getName()}§e."));
					});
				}
			}));
	}

	private function openMembers(Player $player, string $id) : void {
		$plot = $this->plugin->getRegistry()->get($id);
		if($plot === null || !$this->plugin->getService()->canManage($player, $plot)){
			$player->sendMessage($this->plugin->message("§cVocê não gerencia mais este terreno."));
			return;
		}
		$form = new MenuForm("Confiáveis: " . $plot->getName(), "Confiáveis podem fazer tudo no terreno, menos gerenciá-lo.\nToque em um nome para remover.");
		$form->button("§2+ Adicionar jogador", fn(Player $p) => $this->openAddMember($p, $id));
		foreach($plot->getMembers() as $name => $member){
			$name = (string) $name;
			$display = $member["display"];
			$form->button("§c- " . $display, fn(Player $p) => $this->confirmRemoveMember($p, $id, $name, $display));
		}
		$player->sendForm($form->button("Voltar", fn(Player $p) => $this->reopen($p, $id)));
	}

	private function openAddMember(Player $player, string $id) : void {
		$plot = $this->plugin->getRegistry()->get($id);
		if($plot === null){
			return;
		}
		$online = [];
		foreach($this->plugin->getServer()->getOnlinePlayers() as $other){
			$lower = strtolower($other->getName());
			if($other !== $player && $lower !== $plot->getOwner() && !isset($plot->getMembers()[$lower])){
				$online[] = $other->getName();
			}
		}
		$options = array_merge(["(digitar o nome abaixo)"], $online);
		$player->sendForm((new CustomForm("Adicionar confiável"))
			->dropdown("online", "Jogador online", $options)
			->input("nome", "Ou digite o nome (pode estar offline)", "ex.: Steve")
			->onSubmit(function(Player $p, FormResponse $r) use ($id, $options) : void {
				$name = trim($r->string("nome"));
				if($name === "" && $r->index("online") > 0){
					$name = $options[$r->index("online")];
				}
				if($name === ""){
					$p->sendMessage($this->plugin->message("§cEscolha um jogador ou digite um nome."));
					return;
				}
				$this->attempt($p, function() use ($p, $id, $name) : void {
					$added = $this->plugin->getService()->addMember($p, $id, $name);
					$p->sendMessage($this->plugin->message("§f{$added}§a agora é confiável."));
				});
			}));
	}

	private function confirmRemoveMember(Player $player, string $id, string $name, string $display) : void {
		$player->sendForm((new ModalForm("Remover confiável", "Tirar o acesso de §f{$display}§r?", "Remover", "Cancelar"))
			->onChoice(function(Player $p, bool $yes) use ($id, $name, $display) : void {
				if(!$yes){
					return;
				}
				$this->attempt($p, function() use ($p, $id, $name, $display) : void {
					$this->plugin->getService()->removeMember($p, $id, $name);
					$p->sendMessage($this->plugin->message("§f{$display}§e não é mais confiável."));
				});
			}));
	}

	private function openFlags(Player $player, string $id) : void {
		$plot = $this->plugin->getRegistry()->get($id);
		if($plot === null || !$this->plugin->getService()->canManage($player, $plot)){
			$player->sendMessage($this->plugin->message("§cVocê não gerencia mais este terreno."));
			return;
		}
		$form = (new CustomForm("Permissões públicas"))->label("O que qualquer jogador (sem ser confiável) pode fazer no terreno §f{$plot->getName()}§r:");
		foreach(Flag::cases() as $flag){
			$form->toggle($flag->value, $flag->label(), $plot->getFlag($flag));
		}
		$player->sendForm($form->onSubmit(function(Player $p, FormResponse $r) use ($id) : void {
			$this->attempt($p, function() use ($p, $r, $id) : void {
				$values = [];
				foreach(Flag::cases() as $flag){
					$values[$flag->value] = $r->bool($flag->value);
				}
				$this->plugin->getService()->setFlags($p, $id, $values);
				$p->sendMessage($this->plugin->message("§aPermissões públicas atualizadas."));
			});
		}));
	}

	// ---------------- administração ----------------

	private function addAdminButton(MenuForm $form, Player $player, string $id) : void {
		if($player->hasPermission(Terrenos::PERMISSION_ADMIN)){
			$form->button("§4Administrar", fn(Player $p) => $this->openAdmin($p, $id));
		}
	}

	private function openAdmin(Player $player, string $id) : void {
		$plot = $this->plugin->getRegistry()->get($id);
		if($plot === null || !$player->hasPermission(Terrenos::PERMISSION_ADMIN)){
			return;
		}
		$form = new MenuForm("Administrar: " . $plot->getName(), Format::info($plot, $this->plugin->getEconomy(), $this->plugin->getGuard()->getRules(), time()));
		$form->button("Definir preço e nome", fn(Player $p) => $this->openEdit($p, $id));
		if($plot->hasOwner()){
			$form->button("§2Confiáveis", fn(Player $p) => $this->openMembers($p, $id));
			$form->button("§6Permissões públicas", fn(Player $p) => $this->openFlags($p, $id));
			$form->button("§cLiberar (tirar o dono)", fn(Player $p) => $this->confirmAdmin($p, $id, "Liberar o terreno §f{$plot->getName()}§r? O dono perde o terreno sem reembolso.", function() use ($id) : string {
				$target = $this->plugin->getService()->require($id);
				$this->plugin->getService()->release($target, "liberado por um administrador");
				return "§eTerreno §f{$target->getName()}§e liberado.";
			}));
		}
		$form->button("§4Apagar terreno", fn(Player $p) => $this->confirmAdmin($p, $id, "Apagar o terreno §f{$plot->getName()}§r de vez? Os blocos ficam, mas a proteção some.", function() use ($id) : string {
			$deleted = $this->plugin->getService()->delete($id);
			return "§eTerreno §f{$deleted->getName()}§e apagado.";
		}));
		$player->sendForm($form->button("Voltar", fn(Player $p) => $this->reopen($p, $id)));
	}

	private function openEdit(Player $player, string $id) : void {
		$plot = $this->plugin->getRegistry()->get($id);
		if($plot === null){
			return;
		}
		$player->sendForm((new CustomForm("Editar terreno"))
			->input("nome", "Nome", default: $plot->getName())
			->input("preco", "Preço do aluguel (0 = grátis)", default: (string) $plot->getPrice())
			->onSubmit(function(Player $p, FormResponse $r) use ($id) : void {
				if(!$p->hasPermission(Terrenos::PERMISSION_ADMIN)){
					return;
				}
				$price = $r->parseFloat("preco", 0.0, 1e15);
				if($price === null){
					$p->sendMessage($this->plugin->message("§cPreço inválido."));
					return;
				}
				$this->attempt($p, function() use ($p, $r, $id, $price) : void {
					$this->plugin->getService()->rename($id, $r->string("nome"));
					$plot = $this->plugin->getService()->setPrice($id, $price);
					$p->sendMessage($this->plugin->message("§aTerreno §f{$plot->getName()}§a: preço " . Format::price($this->plugin->getEconomy(), $plot->getPrice()) . "."));
				});
			}));
	}

	/**
	 * @param Closure() : string $action devolve a mensagem de sucesso
	 */
	private function confirmAdmin(Player $player, string $id, string $question, Closure $action) : void {
		$player->sendForm((new ModalForm("Confirmar", $question, "Confirmar", "Cancelar"))
			->onChoice(function(Player $p, bool $yes) use ($action) : void {
				if(!$yes || !$p->hasPermission(Terrenos::PERMISSION_ADMIN)){
					return;
				}
				$this->attempt($p, function() use ($p, $action) : void {
					$p->sendMessage($this->plugin->message($action()));
				});
			}));
	}

	private function reopen(Player $player, string $id) : void {
		$plot = $this->plugin->getRegistry()->get($id);
		if($plot !== null){
			$this->open($player, $plot);
		}
	}

	/**
	 * Roda a operação e mostra a mensagem de erro, se houver.
	 *
	 * @param Closure() : void $operation
	 */
	private function attempt(Player $player, Closure $operation) : void {
		try{
			$operation();
		}catch(ActionFailed $e){
			$player->sendMessage($this->plugin->message($e->getMessage()));
		}
	}

	/**
	 * Lista dos terrenos do jogador num menu (toque para abrir).
	 *
	 * @param list<Plot> $plots
	 */
	public function openList(Player $player, array $plots, string $title) : void {
		$now = time();
		$rules = $this->plugin->getGuard()->getRules();
		$form = new MenuForm($title, count($plots) === 0 ? "Nenhum terreno." : "Toque em um terreno para abrir o menu dele.");
		foreach($plots as $plot){
			$id = $plot->getId();
			$form->button("§f{$plot->getName()} §8($id)\n§r" . Format::lease($plot, $rules, $now), fn(Player $p) => $this->reopen($p, $id));
		}
		$player->sendForm($form->button("Fechar"));
	}
}
