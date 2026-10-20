<?php

declare(strict_types=1);

namespace Unknown\Terrenos\model;

use function mb_strtolower;
use function str_replace;
use function trim;

/**
 * Permissões públicas de um terreno: o que qualquer jogador (sem ser dono nem
 * confiável) pode fazer dentro dele. O valor é o identificador usado na config,
 * nos comandos e no arquivo de dados.
 */
enum Flag : string {

	case DESTROY = "quebrar";
	case BUILD = "construir";
	case CONTAINERS = "conteineres";
	case DOORS = "portas";
	case INTERACT = "interagir";
	case ENTITIES = "entidades";
	case PVP = "pvp";

	/** Nome mostrado nos menus e mensagens. */
	public function label() : string {
		return match($this){
			self::DESTROY => "Quebrar blocos",
			self::BUILD => "Construir e modificar blocos",
			self::CONTAINERS => "Abrir contêineres",
			self::DOORS => "Usar portas, alçapões e porteiras",
			self::INTERACT => "Interagir (botões, alavancas, molduras...)",
			self::ENTITIES => "Ferir animais e entidades",
			self::PVP => "PvP entre jogadores"
		};
	}

	/**
	 * Aceita o identificador com ou sem acento e sem diferenciar maiúsculas
	 * ("contêineres" e "Conteineres" viram {@see CONTAINERS}).
	 */
	public static function parse(string $text) : ?self {
		$normalized = str_replace(["ê", "é", "á", "ã", "í", "ó", "õ", "ú", "ç"], ["e", "e", "a", "a", "i", "o", "o", "u", "c"], mb_strtolower(trim($text), "UTF-8"));
		return self::tryFrom($normalized);
	}

	/**
	 * @return array<string, bool> todas as bandeiras desligadas
	 */
	public static function allOff() : array {
		$flags = [];
		foreach(self::cases() as $flag){
			$flags[$flag->value] = false;
		}
		return $flags;
	}
}
