<?php

declare(strict_types=1);

namespace Unknown\Terrenos\model;

/** Situação do aluguel de um terreno num dado momento. */
enum LeasePhase {

	/** Sem dono: à venda. */
	case VACANT;
	/** Com dono e sem vencimento (definido por um administrador). */
	case PERMANENT;
	/** Aluguel em dia. */
	case ACTIVE;
	/** Falta pouco para vencer (dentro da janela de aviso). */
	case ENDING;
	/** Venceu, mas ainda está na carência: o dono mantém tudo e pode renovar. */
	case GRACE;
	/** Carência acabou: o terreno deve ser liberado. */
	case EXPIRED;

	/** True se o dono ainda tem a posse (inclui a carência). */
	public function isHeld() : bool {
		return $this === self::PERMANENT || $this === self::ACTIVE || $this === self::ENDING || $this === self::GRACE;
	}
}
