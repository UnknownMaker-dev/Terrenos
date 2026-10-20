<?php

declare(strict_types=1);

namespace Unknown\Terrenos\model;

/** Papel de um jogador em relação a um terreno. */
enum Role {

	case OWNER;
	/** Confiável: acesso total, mas não gerencia o terreno. */
	case MEMBER;
	case VISITOR;

	public function label() : string {
		return match($this){
			self::OWNER => "dono",
			self::MEMBER => "confiável",
			self::VISITOR => "visitante"
		};
	}
}
