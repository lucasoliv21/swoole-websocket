<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

$server = new App\MyServer();
$server->main();

/**
 * @TODO - Bugfix - TOP 5 Win Rates (Média prioridade): Quando o servidor inicia,
 * ele envia uma lista de times para o frontend de top 5, porém todos eles
 * estão empatados com 0% de winrate, com isto, a ordem na verdade tem 
 * a ver com a ordem que os times foram adicionados na tabela.
 * A task é mudar esse comportamento para algo pensado: seja a lista iniciar vazia
 * e depois ir adicionando a medida que os jogos passem, ou então fazer algo onde todos
 * eles estão empatados em primeiro lugar... algo do tipo, sua escolha.
 * 
 * @TODO - Nova funcionalidade - Cronometro (Alta prioridade): Seria interessante implementar
 * um sistema de timing de status do jogo, onde o frontend possa mostrar um cronometro
 * ou uma barra de progresso que mostre o tempo restante para o fim da votação, ou quanto
 * falta para iniciar um jogo. Esse temporizador precisa ser sincronizado para todos os jogadores
 * incluindo jogadores que entram no meio do jogo. Imagine um jogador que entra no meio de
 * uma partida do Aviator e pega a vela já em 12.51x.
 * 
 * @TODO - Nova funcionalidade- Últimos resultados (Baixa prioridade): Implementar uma espécie
 * de histórico de resultados, onde o frontend possa mostrar os últimos 20 jogos que aconteceram
 * e seus resultados pro jogador, semelhante ao histórico do Aviator ou Roleta.
 * 
 * @TODO - Melhoria & Segurança - Cooldown de votação (Alta prioridade): Implementar um sistema
 * de cooldown para a votação, onde um jogador só pode votar a cada 1 segundos, evitando que aconteça
 * spam de votos, e não votar em dois times ao mesmo tempo.
 * 
 * @IDEA - Nova funcionalidade - Mecânica de skillcheck: Ao clicar pra votar, o jogador precisa
 * acertar um botão no momento certo para confirmar o voto, caso erre o botão o voto vale menos
 * ou é cancelado. Pode ser algo simples como um botão que fica piscando e o jogador precisa clicar
 * no momento certo.
 */
