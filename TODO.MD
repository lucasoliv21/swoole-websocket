# TODO List

## Bugfixes

### 🛠️ TOP 5 Win Rates (Média prioridade)
**Problema:**
- Quando o servidor inicia, ele envia uma lista de times para o frontend com o Top 5 Win Rates.
- No entanto, todos os times estão "empatados" com 0% de winrate, e mostra 1,2,3,4 e 5.
- A ordem está sendo definida apenas pela sequência de inserção na tabela.

**Solução sugerida:**
- Fazer com que a lista inicie vazia e vá sendo preenchida conforme os jogos acontecem.
- Alternativamente, todos os times podem começar empatados em primeiro lugar.
- Avaliar a melhor abordagem para um comportamento mais adequado.

---

## Novas Funcionalidades

### ⏳ Cronômetro (Alta prioridade)
**Objetivo:**
- Implementar um sistema de sincronização de tempo para exibir contadores visuais no frontend.
- Deve permitir mostrar o tempo restante para:
  - Tempo que falta pro inicio da votação.
  - Duração da votação
  - Tempo até o início da próxima rodada.
  
**Requisitos:**
- O temporizador deve ser sincronizado para todos os jogadores em tempo real.
- Jogadores que entram no meio da partida devem ver o tempo correto.
- Exemplo: Se um jogador entra no meio de um jogo do Aviator, ele precisa visualizar o tempo correto da rodada em andamento (exemplo: "12.51x").

---

### 📜 Últimos Resultados (Baixa prioridade)
**Objetivo:**
- Criar um histórico de partidas recentes acessível pelo frontend.

**Requisitos:**
- Mostrar os últimos 20 jogos finalizados e seus respectivos resultados.
- Semelhante ao histórico de jogos no Aviator ou Roleta.
