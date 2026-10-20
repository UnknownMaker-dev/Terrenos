# Terrenos

Terrenos alugáveis e protegidos para servidores PocketMine-MP 5: o administrador demarca as
áreas, os jogadores alugam com a economia do servidor, escolhem quem tem acesso e o que
visitantes podem fazer. Aluguel vencido tem carência antes de o terreno voltar à venda.

> 🆓 **Projeto open source e gratuito**, distribuído sob a [licença MIT](LICENSE). Você pode usar,
> estudar, modificar e redistribuir o código livremente, inclusive em servidores comerciais,
> desde que mantenha o aviso de licença.

## Recursos

- **Terrenos por área**: criados pela seleção `pos1`/`pos2` ou por coordenadas no console.
  Por padrão cobrem **do fundo ao topo do mundo** (`full-height`), então marcar os cantos em pé
  no chão já protege tudo acima e abaixo.
- **Sem sobreposição**: um terreno que encosta em outro (mesmo que num único bloco) é recusado,
  e o administrador vê qual terreno seria invadido.
- **Aluguel**: compra e renovação pela economia do servidor (qualquer plugin, configurado só
  pelo `config.yml`), duração configurável, limite de tempo pago à frente, limite de terrenos por
  jogador. Preço 0 = terreno grátis.
- **Vencimento com carência**: aviso antes de vencer; depois do vencimento o dono mantém o
  terreno durante a carência e é lembrado ao entrar e periodicamente. Terminada a carência, o
  terreno volta à venda (sem dono, sem confiáveis, bandeiras padrão) e, se configurado, todos os
  contêineres dentro dele são esvaziados — mesmo que o mundo esteja descarregado na hora.
- **Dono e confiáveis**: confiáveis têm acesso total, mas só o dono gerencia. A posse é guardada
  pelo nome em minúsculas e pelo xuid quando conhecido: quem pega o nome de outro jogador não
  herda o terreno, e quem troca de nome (mesmo xuid) continua dono.
- **Bandeiras públicas** (o que visitantes podem fazer): `quebrar`, `construir`, `conteineres`,
  `portas`, `interagir`, `entidades` e `pvp`.
- **Proteção completa** (ver lista abaixo), com aviso discreto (tip) e intervalo mínimo entre avisos.
- **Menus por formulário** que mudam conforme o terreno está à venda, é seu ou é de outra pessoa,
  e um menu de administração. Toda resposta é revalidada (terreno apagado, vendido ou com preço
  alterado enquanto o menu estava aberto).
- **Índice espacial** por mundo e chunk: cada clique ou explosão só confere os terrenos daquele
  chunk, não importa quantos terrenos existam.

### O que é protegido

| Situação | Como |
|---|---|
| Quebrar e colocar blocos | `BlockBreakEvent` e `BlockPlaceEvent` (cada bloco da colocação, ex.: portas e camas) |
| Baldes | `PlayerBucketEmptyEvent` (bloco de destino do líquido) e `PlayerBucketFillEvent` (bloco do líquido) |
| Contêineres | baús, barris, fornalhas, funis, shulkers, suportes de poção, estantes entalhadas, fogueiras, jukebox, atril, vasos e caldeirões |
| Baú duplo atravessando a borda | `ChestPairEvent` cancelado quando os dois baús estão em terrenos diferentes (ou um fora) |
| Molduras | colocar/girar item (clique direito) e tirar item (clique esquerdo) |
| Portas, alçapões, porteiras | bandeira `portas` |
| Botões, alavancas, repetidores, comparadores, sensor de luz, bloco musical, camas, bolos, bigornas, âncora de renascimento, sino, sinalizador, tear, ovo do dragão | bandeira `interagir` |
| Placas de pressão | `PressurePlateUpdateEvent`: visitante sem a bandeira `interagir` não aciona (se alguém autorizado também estiver em cima, aciona) |
| Ferramentas que alteram blocos | enxada, pá, machado, tesoura e farinha de osso usadas no bloco |
| Fogo | isqueiro e carga de fogo (no bloco clicado e no bloco onde o fogo nasceria), apagar fogo com a mão |
| Placas | edição (`SignChangeEvent`) e tinta/cera aplicadas |
| Pinturas, ovos de spawn, cristal do End e itens de outros plugins com uso em blocos | colocar (bandeira `construir`); quebrar pintura (bandeira `quebrar`) |
| Fogo de fora | `BlockBurnEvent` e `BlockSpreadEvent`: fogo vindo de fora nunca queima nem se espalha para dentro |
| Líquidos de fora | `BlockSpreadEvent` (água/lava escorrendo para dentro) e `BlockFormEvent` (pedra formada por líquido de fora) |
| Explosões | `BlockExplodeEvent` e `EntityExplodeEvent`: blocos e focos de incêndio dentro de terrenos são tirados da explosão (o resto explode normalmente) |
| Plantações | `EntityTrampleFarmlandEvent`: jogador sem permissão e mobs não pisoteiam |
| Animais e entidades | dano de jogadores (inclusive flechas e outros projéteis) sem a bandeira `entidades` |
| PvP | bandeira `pvp`: com ela desligada ninguém ataca nem é atacado no terreno (vale para atacante e vítima) |

## Requisitos

- [PocketMine-MP](https://github.com/pmmp/PocketMine-MP) 5 (API `5.0.0`) ou forks compatíveis, como o Altay.
- [Forja](https://github.com/UnknownMaker-dev/Forja) (biblioteca obrigatória).
- Opcional: um plugin de economia, para cobrar a compra e a renovação.

## Instalação

1. Instale a [Forja](https://github.com/UnknownMaker-dev/Forja) em `plugins/`.
2. Coloque o `.phar` do Terrenos (ou esta pasta) em `plugins/`. Para usar a pasta do código
   diretamente é preciso ter o plugin [DevTools](https://github.com/pmmp/DevTools).
3. Reinicie o servidor. O `config.yml` é criado em `plugin_data/Terrenos/`.
4. (Opcional) Configure a seção `economy` do `config.yml` e use `/terreno recarregar`.

Sem economia configurada o plugin funciona normalmente e avisa no console uma vez; só a compra
e a renovação de terrenos com preço maior que 0 ficam indisponíveis.

## Comandos

Comando principal `/terreno` (alias `/terrenos`). Os subcomandos também aceitam o nome em inglês
entre parênteses. `[id]` opcional = o terreno onde você está (e, para quem tem um só, o seu).

| Comando | Permissão | Descrição |
|---|---|---|
| `/terreno` | `terrenos.use` | Abre o menu do terreno onde você está (ou a lista dos seus). |
| `/terreno <id>` | `terrenos.use` | Abre o menu de um terreno pelo id. |
| `/terreno info [id]` | `terrenos.use` | Mostra dono, situação, preço, área, confiáveis e bandeiras (no console, com id). |
| `/terreno comprar [id]` (`buy`) | `terrenos.use` | Compra o terreno (com confirmação). |
| `/terreno renovar [id]` (`renew`) | `terrenos.use` | Renova o aluguel (com confirmação). |
| `/terreno meus` (`mine`) | `terrenos.use` | Lista os seus terrenos. |
| `/terreno confiar <jogador> [id]` (`trust`) | `terrenos.use` | Dá acesso total a um jogador (online ou offline). |
| `/terreno desconfiar <jogador> [id]` (`untrust`) | `terrenos.use` | Tira o acesso. |
| `/terreno bandeira <bandeira> <sim\|não> [id]` (`flag`) | `terrenos.use` | Libera ou bloqueia algo para visitantes. |
| `/terreno abandonar [id]` (`abandon`) | `terrenos.use` | Desiste do terreno, sem reembolso (com confirmação). |
| `/terreno pos1` / `/terreno pos2` | `terrenos.admin` | Marca os cantos na sua posição. |
| `/terreno criar <id>` (`create`) | `terrenos.admin` | Cria o terreno com os cantos marcados. |
| `/terreno criar <id> <mundo> <x1> <z1> <x2> <z2> [y1 y2]` | `terrenos.admin` | Cria por coordenadas (funciona no console). Sem `y1 y2`, altura cheia. |
| `/terreno preco <id> <valor>` (`setprice`) | `terrenos.admin` | Define o preço do aluguel (0 = grátis). |
| `/terreno nome <id> <nome>` (`rename`) | `terrenos.admin` | Muda o nome exibido. |
| `/terreno dono <id> <jogador> [duração\|permanente]` (`setowner`) | `terrenos.admin` | Entrega o terreno sem cobrar (padrão: uma duração de aluguel). |
| `/terreno liberar <id>` (`release`) | `terrenos.admin` | Tira o dono e põe o terreno à venda. |
| `/terreno apagar <id>` (`delete`) | `terrenos.admin` | Apaga o terreno (os blocos ficam, a proteção some). |
| `/terreno lista [página]` (`list`) | `terrenos.admin` | Lista todos os terrenos. |
| `/terreno recarregar` (`reload`) | `terrenos.admin` | Relê o `config.yml`. |
| `/terreno ajuda` | — | Lista os subcomandos que você pode usar. |

O id aceita de 1 a 32 letras minúsculas, números, `-` e `_`. Durações aceitam `30d`, `12h`,
`1d 12h`, `45m` ou segundos.

## Permissões

| Permissão | Padrão | Descrição |
|---|---|---|
| `terrenos.use` | todos | Usar `/terreno`: ver, comprar, renovar e gerenciar os próprios terrenos. |
| `terrenos.admin` | op | Criar, apagar, precificar, listar e liberar terrenos, definir donos e recarregar a config. |
| `terrenos.bypass` | op | Ignorar a proteção de todos os terrenos (não vale para o PvP, que segue a bandeira). |

## Configuração

### `config.yml`

| Chave | Padrão | Descrição |
|---|---|---|
| `prefix` | `"&6[Terrenos]&r "` | Prefixo das mensagens (`&` vira cor). |
| `plots.default-price` | `10000` | Preço dos terrenos novos. |
| `plots.full-height` | `true` | Terreno criado pela seleção vai do fundo ao topo do mundo. |
| `plots.max-per-player` | `3` | Terrenos por jogador (0 = sem limite). |
| `plots.max-members` | `10` | Confiáveis por terreno (0 = sem limite). |
| `plots.default-flags` | tudo `false` | Bandeiras de um terreno recém-comprado ou liberado. |
| `rent.duration` | `"30d"` | Duração de cada compra ou renovação. |
| `rent.max-ahead` | `"90d"` | Máximo pago à frente ao renovar (`"0"` = sem limite). |
| `rent.warn-before` | `"3d"` | Avisar o dono quando faltar até isto para vencer. |
| `rent.grace-period` | `"3d"` | Carência depois do vencimento (`"0"` = libera na hora). |
| `rent.reminder-interval` | `"30m"` | Intervalo dos lembretes para donos online. |
| `rent.check-interval` | `"1m"` | Intervalo da verificação de vencimentos. |
| `rent.clear-containers-on-release` | `false` | Esvaziar os contêineres ao liberar um terreno vencido ou abandonado. |
| `protection.fire-inside` | `false` | Fogo iniciado dentro do terreno pode se espalhar/queimar nele. |
| `protection.explosions-inside` | `false` | Explosões iniciadas dentro do terreno podem destruir blocos dele. |
| `protection.unprotected-entities` | `["minecraft:zombie"]` | Entidades que qualquer um pode ferir em terreno alheio. |
| `protection.notice-cooldown` | `1.5` | Segundos mínimos entre avisos de "não pode". |
| `economy.*` | vazio | Ponte de economia (abaixo). |

Exemplo de economia (os nomes de classe e métodos são do **seu** plugin de economia):

```yaml
economy:
  class: "MinhaEconomia\\Main"
  instance: getInstance
  plugin: MinhaEconomia
  balance: getMoney
  take: reduceMoney
  give: addMoney
  format: ""
  currency: "moedas"
```

Valores inválidos (tipo errado, duração ilegível, bandeira desconhecida) geram um aviso no
console e usam o padrão; YAML quebrado nunca derruba o servidor.

### `dados.json`

Gerado na pasta de dados: cada terreno com nome, área, preço, dono (nome, xuid e vencimento),
confiáveis, bandeiras e limpeza pendente. É gravado (de forma atômica) a cada alteração e ao
desligar. Entradas inválidas ou sobrepostas são ignoradas com aviso; um arquivo corrompido é
movido para `dados.json.corrompido-<data>` em vez de apagado. Edite só com o servidor parado.

## Como usar

1. Vá a um canto da área e use `/terreno pos1`; vá ao canto oposto e use `/terreno pos2`
   (a altura não importa com `full-height: true`).
2. `/terreno criar casa1` — ou, pelo console, `/terreno criar casa1 world 0 0 15 15`.
3. (Opcional) `/terreno preco casa1 50000` e `/terreno nome casa1 Casa da Praia`.
4. Confira com `/terreno lista` e `/terreno info casa1`.
5. O jogador entra no terreno, usa `/terreno` e compra. Pelo mesmo menu ele renova, adiciona
   confiáveis e escolhe as permissões públicas.

Enquanto está à venda, o terreno fica protegido: só quem tem `terrenos.bypass` mexe nele.

## Limitações

- Itens jogados e mobs continuam acionando placas de pressão dentro de terrenos (de propósito,
  para não quebrar mecanismos do próprio dono); só jogadores sem permissão são barrados.
- Explosões não destroem blocos de terrenos, mas o dano da explosão a jogadores e entidades
  segue as regras normais do servidor.
- Com `full-height: false` (ou `criar` com `y1 y2`), um funil colocado logo abaixo do terreno,
  fora dele, consegue puxar itens de um contêiner na camada mais baixa do terreno. Com altura
  cheia (padrão) isso não é possível.

## Contribuindo

Issues e pull requests são bem-vindos. Ao contribuir, você concorda que sua contribuição será
licenciada sob a mesma licença MIT do projeto.

## Licença

Distribuído sob a licença MIT — veja [LICENSE](LICENSE).

## Aviso de responsabilidade

Este plugin é fornecido gratuitamente, **"no estado em que se encontra" (as is)**, sem garantia
de qualquer tipo, expressa ou implícita. Os autores e mantenedores **não se responsabilizam** por
perda de dados, mundos ou itens, quedas ou falhas do servidor, prejuízos financeiros, nem por
qualquer uso que terceiros façam deste código ou dos servidores em que ele for instalado. Faça
backup antes de instalar ou atualizar e teste em um servidor de testes antes de usar em
produção. Ao usar, modificar ou redistribuir este plugin, você assume total responsabilidade
por isso.

Este projeto não é afiliado, endossado ou patrocinado pela Mojang, Microsoft, PocketMine-MP,
nem pelos detentores dos direitos de obras ou marcas eventualmente citadas.
