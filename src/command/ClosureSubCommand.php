<?php

declare(strict_types=1);

namespace Unknown\Terrenos\command;

use Closure;
use pocketmine\command\CommandSender;
use Unknown\Forja\command\Args;
use Unknown\Forja\command\SubCommand;

/**
 * Subcomando cuja execução é uma closure, para manter toda a lógica do /terreno
 * num só lugar ({@see PlotCommand}).
 */
final class ClosureSubCommand extends SubCommand {

	/**
	 * @param list<string> $aliases
	 * @param Closure(CommandSender, Args) : void $handler
	 */
	public function __construct(
		string $name,
		string $description,
		string $usage,
		array $aliases,
		?string $permission,
		bool $playerOnly,
		private Closure $handler
	) {
		parent::__construct($name, $description, $usage, $aliases, $permission, $playerOnly);
	}

	public function run(CommandSender $sender, Args $args) : void {
		($this->handler)($sender, $args);
	}
}
