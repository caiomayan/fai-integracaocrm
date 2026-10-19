# 009 — Tarefas

| # | Tarefa | CA | Status |
|---|---|---|---|
| T0 | Juntar a 1.10.0 (spec 007) na linha 1.9.2: ramo `release/1.10.1` a partir da `main` | CA-49 | ✅ |
| T1 | Testes (vermelho) do filtro `username` na 4.5 | CA-47 | ✅ |
| T2 | Filtro na 4.5 e no `rest_json`, versão 1.10.1 (verde, phpcs) | CA-47, CA-49 | ✅ |
| T3 | Revisão de segurança (Muralha) | CA-49 | ✅ |
| T4 | API, navegador (retorno) e atualização 1.9.2 → 1.10.1 (Lupa) | CA-48, CA-49 | ✅ |
| T5 | Contrato, OpenAPI, roteiro do painel, release v1.10.1 | CA-50 | ✅ |

## Resultado (19/10)
- **Junção:** a `main` (1.9.2) recebeu o cherry-pick da 1.10.0. Nenhum arquivo da 1.10.0 mudou e nenhuma linha da 1.9.2 se perdeu, conferido pelo Muralha contra `release/1.10` e `main`.
- **PHPUnit:** 182 testes, 533 asserções, verde. São 11 testes novos do filtro e 1 do `rest_json`. **moodle-cs:** 0 erros e 0 avisos.
- **Segurança (Muralha):** sem achados. Comparação exata com parâmetro ligado, sem curinga, só contas locais e não excluídas, o mesmo universo de estudantes do curso e nada além do que o token já vê.
- **QA (Lupa)**, na stack `fai-hotfix`, com o banco vindo da 1.9.2:
  - **atualização:** 1.9.2 → 1.10.1, com o segredo do retorno criado e as duas configurações novas vazias; o diagnóstico deu 13/13 OK;
  - **filtro:** 25 casos e 0 falhas (com nota, pendente, sem tentativa, inexistente, maiúsculas e espaços, parcial, `%`/`_`, professor e admin, datas, vazio e 101 caracteres → 400);
  - **retorno no navegador:** com a URL vazia, o candidato fica no Moodle; com a URL de teste, "Prova enviada com sucesso!" e, em 5 s, `?status=concluida&cmid=6`, deslogado; pelo login normal, sem desvio; console limpo;
  - **notas da 1.9.2:** os 6 cenários iguais aos de antes;
  - **Bruno:** 33/33.
- **Pacote:** `dist/local_faicrm-1.10.1.zip`, SHA-256 `fd801f6c3664ec1dbf8788692a61e7a6ac04f0ace2f4e552f149d35475119fed`.
