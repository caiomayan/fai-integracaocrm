# 008 — Notas da redação e das objetivas nos resultados (urgência, 1.9.2)

## Contexto
Demanda urgente do cliente, de 15/10, para o lançamento do Vestibular On-line no dia seguinte. A aprovação automática no CRM precisa saber se o candidato tirou **mais que zero na redação e mais que zero nas objetivas**, e a `nota` total sozinha não permite isso. Exemplo: a nota 740 do piloto não diz se ele cumpriu os dois requisitos.

A versão sai **a partir da v1.9.1**, num ramo de urgência (`hotfix/1.9.2`). A 1.10.0 (spec 007) fica de fora agora e recebe esta mudança depois, com calma. O CRM já foi publicado esperando os campos novos.

## Escopo fechado
Só a operação **4.5 `local_faicrm_get_resultados_vestibular`** ganha dois campos em cada candidato. Ficam como estão:
- as outras operações;
- os tokens e os serviços;
- o SSO;
- os campos atuais da 4.5.

## Decisões
- **D-01 Campos novos:**
  - **`nota_redacao`**: nota da **questão da posição 1** da prova, na escala do resultado (0 a 200 na FAI);
  - **`nota_objetivas`**: a soma de **todas as outras questões**, na mesma escala (0 a 800 na FAI).

  Os dois são número ou `null`.
- **D-02 Mesma escala e mesma soma da `nota`:** cada nota de questão é convertida pela escala da prova (`nota máxima da prova ÷ soma das notas máximas das questões`). A redação mais as objetivas dá a nota da tentativa, que é o valor do campo `nota`.
- **D-03 Questão da posição 1:** é a **primeira questão da prova, na ordem da prova**, e não o id 1 do banco de questões. Itens só de texto (tipo "Descrição"), que não valem nota, não contam como questão.
- **D-04 Mesma tentativa do resultado final**, conforme o método de avaliação da prova:
  - **maior nota:** a tentativa de maior nota (no empate, a primeira);
  - **primeira:** a primeira tentativa finalizada;
  - **última:** a última finalizada;
  - **média:** a média de cada parte entre as tentativas finalizadas e corrigidas. A soma continua dando a média total.
- **D-05 Correção pendente → `null`, nunca zero.** Se alguma questão de uma tentativa finalizada da prova ainda **espera correção manual**, os dois campos vêm `null`, e o CRM mantém "avaliação sendo preparada".
  - Isso vale para **qualquer** tentativa finalizada, não só a escolhida. Numa recaptação com a 2ª tentativa por corrigir, o resultado ainda pode mudar.
  - Questão **deixada em branco** e encerrada vale **zero**, e não fica pendente.
- **D-06 Sem tentativa finalizada → `null`.** Também é `null` quando a prova não tem questões, ou quando o curso tem **mais de uma prova com tentativa finalizada** do candidato e não dá para saber qual é a do resultado.
- **D-07 Duas casas decimais.** A `nota_objetivas` é calculada como o total da tentativa menos a redação, para a soma bater exatamente.
- **D-08 Desempenho:** o cálculo é feito em lote para a página de 100 candidatos, sem uma consulta por candidato, como os outros campos.
- **D-09 Versão:** **1.9.2** (`version` 2026101302), no ramo `hotfix/1.9.2`.

## Regra do CRM (só para referência; quem aplica é o CRM)
- redação > 0 **e** objetivas > 0 → aprovação automática, mensagem de parabéns e pré-matrícula;
- redação = 0 **ou** objetivas = 0, com a correção concluída → nova chance;
- `null` (correção pendente) → "avaliação sendo preparada", sem aprovar nem reprovar.

Não há nota mínima total nesse processo. A mínima de 400 é só do ENEM.

## Critérios de aceite
- **CA-43** PHPUnit, com uma prova de redação (questão 1, dissertativa) e objetivas, nos cenários:
  - **aprovação:** redação > 0 e objetivas > 0, somando a `nota`;
  - **redação zerada:** a redação corrigida com 0 → `nota_redacao` = 0, e não `null`;
  - **objetivas zeradas:** `nota_objetivas` = 0;
  - **correção pendente:** os dois `null`;
  - **sem tentativa:** os dois `null`;
  - **escala:** a prova com nota máxima diferente da soma das questões;
  - **posição 1:** a redação não é a questão de id 1 do banco, e uma "Descrição" no início não conta;
  - **métodos de avaliação:** maior, primeira, última e média, numa recaptação;
  - **redação em branco** → 0;
  - **desempenho:** as consultas não crescem com o número de candidatos.
- **CA-44** No ambiente de teste, pela API (Bruno ou curl): os quatro cenários com candidatos de teste e o exemplo de retorno, sem dados reais.
- **CA-45** Regressão: os outros campos da 4.5 e as outras operações, inclusive o SSO, iguais à 1.9.1; moodle-cs 0/0.
- **CA-46** O contrato e o OpenAPI atualizados, com o exemplo de retorno; release v1.9.2 a partir do ramo `hotfix/1.9.2`.
