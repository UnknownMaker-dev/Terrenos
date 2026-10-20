<?php

declare(strict_types=1);

namespace Unknown\Terrenos\model;

/**
 * Tudo o que um jogador pode tentar fazer dentro de um terreno. Cada ação é
 * liberada para visitantes por uma {@see Flag}.
 */
enum Action {

	case BREAK_BLOCK;
	/** Colocar blocos. */
	case PLACE_BLOCK;
	/** Encher ou esvaziar baldes. */
	case USE_BUCKET;
	/** Ferramentas que alteram blocos (enxada, pá, machado, tesoura, farinha de osso), acender fogo, editar placas. */
	case MODIFY_BLOCK;
	/** Pisotear plantação. */
	case TRAMPLE;
	case OPEN_CONTAINER;
	/** Portas, alçapões e porteiras. */
	case USE_DOOR;
	/** Botões, alavancas, repetidores, camas, bigornas e demais blocos com uso. */
	case USE_BLOCK;
	/** Colocar, girar ou tirar o item de uma moldura. */
	case USE_ITEM_FRAME;
	/** Ferir animais e outras entidades que não são jogadores (pinturas contam como {@see BREAK_BLOCK}). */
	case HURT_ENTITY;
	/** Atacar outro jogador. */
	case PVP;

	public function flag() : Flag {
		return match($this){
			self::BREAK_BLOCK, self::TRAMPLE => Flag::DESTROY,
			self::PLACE_BLOCK, self::USE_BUCKET, self::MODIFY_BLOCK => Flag::BUILD,
			self::OPEN_CONTAINER => Flag::CONTAINERS,
			self::USE_DOOR => Flag::DOORS,
			self::USE_BLOCK, self::USE_ITEM_FRAME => Flag::INTERACT,
			self::HURT_ENTITY => Flag::ENTITIES,
			self::PVP => Flag::PVP
		};
	}

	/** Texto para a mensagem "Você não pode ... aqui". */
	public function describe() : string {
		return match($this){
			self::BREAK_BLOCK => "quebrar blocos",
			self::PLACE_BLOCK => "construir",
			self::USE_BUCKET => "usar baldes",
			self::MODIFY_BLOCK => "modificar blocos",
			self::TRAMPLE => "pisotear plantações",
			self::OPEN_CONTAINER => "abrir contêineres",
			self::USE_DOOR => "usar portas",
			self::USE_BLOCK => "interagir com blocos",
			self::USE_ITEM_FRAME => "mexer em molduras",
			self::HURT_ENTITY => "ferir entidades",
			self::PVP => "atacar jogadores"
		};
	}
}
