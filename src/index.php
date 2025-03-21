<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

$server = new App\MyServer();
$server->main();

/**
 * 
 * @IDEA - Nova funcionalidade - Escolha de times: Antes de começar a partida, os jogadores
 * podem escolher em qual time querem jogar. Ao fim da partida o usuário recebe uma pontuação.
 * 
 * @TODO - Ao fechar a conexão com o jogador, precisamos enviar uma espécie de popup ou alerta antes
 * 
 * @TODO - Sistema de banimento e/ou suspensão de jogadores que já estão na memória.
 * 
 * @IDEA - Nova funcionalidade - Sistema de som: Sempre ter uma música de fundo tocando amena
 * e quando um jogador votar, tocar um som de chute de futebol (lembre de mudar o pitch do som a cada clique)
 * e no fim de partida/início também.
 * 
 * @IDEA - Perfil persistente: Quando o jogador dê um F5 na tela, seria interessante ele
 * continuar com a pontuação que ele tinha antes de atualizar a página.
 * 
 * @IDEA - Sistema de persistência: Quando desligamos o servidor ou ao fim de cada partida,
 * nós devemos salvar o estado dos jogadores num arquivo de texto ou banco de dados externo
 * para que os dados não sejam perdidos, pois atualmente estão só em memória.
 * 
 * @IDEA - Loja de pontuação: O jogador pode trocar suas moedas por itens cosméticos para
 * personalizar sua experiência de jogo. As premições estão por definir, mas pode ser tamanho
 * do emoji de votação, cor diferenciada, maior quantidade de confetes, etc.
 * 
 * @IDEA - Nova funcionalidade - Top 10 Jogadores do dia: Ao fim do dia, os 10 jogadores
 * com mais pontos são exibidos numa lista de ranking. Pode ser uma tabela ou um modal.
 * Quem estiver no top 10 ganha um prêmio especial.
 * 
 * @IDEA - Nova funcionalidade - Mecânica de skillcheck: Ao clicar pra votar, o jogador precisa
 * acertar um botão no momento certo para confirmar o voto, caso erre o botão o voto vale menos
 * ou é cancelado. Pode ser algo simples como um botão que fica piscando e o jogador precisa clicar
 * no momento certo.
 */
