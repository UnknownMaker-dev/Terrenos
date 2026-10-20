<?php

declare(strict_types=1);

namespace Unknown\Terrenos\model;

use InvalidArgumentException;
use function max;

/**
 * Regras de tempo do aluguel (duração, aviso, carência e limite de dias acumulados).
 * Não acessa o servidor: recebe o "agora" como parâmetro, então é fácil de testar.
 */
final class LeaseRules {

	/**
	 * @param int $rentSeconds duração de cada compra/renovação
	 * @param int $warnSeconds avisar o dono quando faltar até isto para vencer
	 * @param int $graceSeconds carência depois do vencimento antes de liberar
	 * @param int $maxAheadSeconds máximo de tempo pago à frente (0 = sem limite)
	 */
	public function __construct(
		private int $rentSeconds,
		private int $warnSeconds,
		private int $graceSeconds,
		private int $maxAheadSeconds = 0
	) {
		if($rentSeconds <= 0){
			throw new InvalidArgumentException("A duração do aluguel precisa ser maior que 0");
		}
		if($warnSeconds < 0 || $graceSeconds < 0 || $maxAheadSeconds < 0){
			throw new InvalidArgumentException("Aviso, carência e limite não podem ser negativos");
		}
	}

	public function getRentSeconds() : int { return $this->rentSeconds; }

	public function getWarnSeconds() : int { return $this->warnSeconds; }

	public function getGraceSeconds() : int { return $this->graceSeconds; }

	public function getMaxAheadSeconds() : int { return $this->maxAheadSeconds; }

	public function phase(Plot $plot, int $now) : LeasePhase {
		if(!$plot->hasOwner()){
			return LeasePhase::VACANT;
		}
		$expiresAt = $plot->getExpiresAt();
		if($expiresAt === null){
			return LeasePhase::PERMANENT;
		}
		if($now < $expiresAt){
			return $now >= $expiresAt - $this->warnSeconds ? LeasePhase::ENDING : LeasePhase::ACTIVE;
		}
		return $now < $expiresAt + $this->graceSeconds ? LeasePhase::GRACE : LeasePhase::EXPIRED;
	}

	/** Momento em que o terreno será liberado (fim da carência); null se não vence. */
	public function releaseAt(Plot $plot) : ?int {
		$expiresAt = $plot->getExpiresAt();
		return $plot->hasOwner() && $expiresAt !== null ? $expiresAt + $this->graceSeconds : null;
	}

	/** Vencimento de uma compra feita agora. */
	public function purchaseExpiry(int $now) : int {
		return $now + $this->rentSeconds;
	}

	/**
	 * Novo vencimento de uma renovação feita agora: soma a duração ao vencimento atual
	 * (ou a agora, se já venceu). null se passaria do limite de tempo pago à frente.
	 */
	public function renewalExpiry(Plot $plot, int $now) : ?int {
		$base = max($now, $plot->getExpiresAt() ?? $now);
		$next = $base + $this->rentSeconds;
		if($this->maxAheadSeconds > 0 && $next - $now > $this->maxAheadSeconds){
			return null;
		}
		return $next;
	}
}
