<?php

declare(strict_types=1);

namespace Unknown\Terrenos\model;

/**
 * O único lugar que decide "o jogador X pode fazer a ação Y na posição Z?".
 * Todos os listeners de proteção perguntam aqui; as regras de fogo, líquidos e
 * explosões (sem jogador) também ficam aqui.
 *
 * Não depende do servidor: recebe o mundo pelo nome, as coordenadas de bloco e o
 * "agora", então pode ser testado isoladamente.
 */
final class Guard {

	public function __construct(
		private PlotRegistry $registry,
		private LeaseRules $rules
	) {}

	public function getRegistry() : PlotRegistry {
		return $this->registry;
	}

	public function getRules() : LeaseRules {
		return $this->rules;
	}

	/**
	 * Papel do jogador no terreno. Depois do fim da carência o dono antigo (e os
	 * confiáveis dele) já são visitantes, mesmo antes da tarefa liberar o terreno.
	 */
	public function roleOf(Plot $plot, Actor $actor, int $now) : Role {
		if(!$this->rules->phase($plot, $now)->isHeld()){
			return Role::VISITOR;
		}
		if($plot->isOwner($actor)){
			return Role::OWNER;
		}
		if($plot->isMember($actor)){
			return Role::MEMBER;
		}
		return Role::VISITOR;
	}

	/**
	 * Regras:
	 * - fora de terreno: tudo liberado;
	 * - PvP: só a bandeira `pvp` do terreno decide (vale para todos, inclusive o dono);
	 * - bypass: liberado;
	 * - dono e confiáveis (com o aluguel em dia ou na carência): liberado;
	 * - visitante em terreno à venda (ou com a carência esgotada): negado;
	 * - visitante em terreno com dono: a bandeira da ação decide.
	 */
	public function decide(Actor $actor, Action $action, string $world, int $x, int $y, int $z, int $now) : Decision {
		$plot = $this->registry->at($world, $x, $y, $z);
		if($plot === null){
			return Decision::allow(null, null);
		}

		if($action === Action::PVP){
			$role = $this->roleOf($plot, $actor, $now);
			return $plot->getFlag(Flag::PVP) ? Decision::allow($plot, $role) : Decision::deny($plot, $role);
		}

		if($actor->hasBypass()){
			return Decision::allow($plot, null);
		}

		$role = $this->roleOf($plot, $actor, $now);
		if($role !== Role::VISITOR){
			return Decision::allow($plot, $role);
		}
		if(!$this->rules->phase($plot, $now)->isHeld()){
			return Decision::deny($plot, $role);
		}
		return $plot->getFlag($action->flag()) ? Decision::allow($plot, $role) : Decision::deny($plot, $role);
	}

	/** Atalho para {@see decide()} quando só importa o sim/não. */
	public function can(Actor $actor, Action $action, string $world, int $x, int $y, int $z, int $now) : bool {
		return $this->decide($actor, $action, $world, $x, $y, $z, $now)->isAllowed();
	}

	/**
	 * PvP: o ataque só acontece se nem a vítima nem o atacante estiverem num terreno
	 * com PvP desligado (impede atacar de dentro de um terreno seguro).
	 *
	 * @param array{int, int, int} $attacker
	 * @param array{int, int, int} $victim
	 */
	public function allowsPvp(string $world, array $attacker, array $victim) : bool {
		foreach([$victim, $attacker] as [$x, $y, $z]){
			$plot = $this->registry->at($world, $x, $y, $z);
			if($plot !== null && !$plot->getFlag(Flag::PVP)){
				return false;
			}
		}
		return true;
	}

	/** True se os dois blocos estão no mesmo terreno (ou ambos fora de qualquer terreno). */
	public function sameZone(string $world, int $x1, int $y1, int $z1, int $x2, int $y2, int $z2) : bool {
		$a = $this->registry->at($world, $x1, $y1, $z1);
		$b = $this->registry->at($world, $x2, $y2, $z2);
		return $a === $b;
	}

	/**
	 * Líquido (ou bloco formado por líquido) chegando a um bloco: liberado fora de
	 * terreno ou quando a origem está no mesmo terreno; negado se vier de fora.
	 */
	public function allowsLiquid(string $world, int $sx, int $sy, int $sz, int $tx, int $ty, int $tz) : bool {
		$target = $this->registry->at($world, $tx, $ty, $tz);
		return $target === null || $target === $this->registry->at($world, $sx, $sy, $sz);
	}

	/**
	 * Fogo se espalhando para um bloco ou queimando um bloco. Dentro de terreno, fogo
	 * vindo de fora é sempre negado; fogo de dentro só é liberado se `$fireInside`.
	 */
	public function allowsFire(string $world, int $sx, int $sy, int $sz, int $tx, int $ty, int $tz, bool $fireInside) : bool {
		$target = $this->registry->at($world, $tx, $ty, $tz);
		if($target === null){
			return true;
		}
		return $fireInside && $target === $this->registry->at($world, $sx, $sy, $sz);
	}

	/**
	 * Uma explosão pode destruir (ou incendiar) o bloco? Fora de terreno sim; dentro,
	 * só se `$explosionsInside` e a explosão começou no mesmo terreno.
	 */
	public function allowsExplosion(string $world, int $sx, int $sy, int $sz, int $tx, int $ty, int $tz, bool $explosionsInside) : bool {
		$target = $this->registry->at($world, $tx, $ty, $tz);
		if($target === null){
			return true;
		}
		return $explosionsInside && $target === $this->registry->at($world, $sx, $sy, $sz);
	}
}
