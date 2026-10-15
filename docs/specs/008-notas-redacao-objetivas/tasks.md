# 008 — Tarefas

| # | Tarefa | CA | Status |
|---|---|---|---|
| T1 | Testes (vermelho) dos cenários na 4.5 | CA-43 | ✅ |
| T2 | `nota_redacao` e `nota_objetivas` em lote, versão 1.9.2 (verde, phpcs) | CA-43, CA-45 | ✅ |
| T3 | Cenários pela API no ambiente de teste e exemplo anonimizado (Lupa) | CA-44 | ✅ |
| T4 | Contrato, OpenAPI, release v1.9.2 do ramo `hotfix/1.9.2` | CA-46 | ✅ |

## Resultado (15/10)
- **PHPUnit:** 97 testes, 303 asserções, verde, sendo 21 deles da 4.5. **moodle-cs:** 0 erros e 0 avisos.
- **QA (Lupa)**, numa prova com uma "Descrição" no slot 1, a redação de 200 no slot 2 e 4 objetivas de 200:
  - aprovação: 150 + 400 = 550;
  - redação zerada: 0 + 800;
  - objetivas zeradas: 120 + 0;
  - pendente: `null`;
  - sem tentativa: `null`;
  - redação em branco: 0 + 200.

  Regressão Bruno: 15/15.
- **Pacote:** `dist/local_faicrm-1.9.2.zip`, SHA-256 `c05c9a786854a731cb83c65609e12a349e6830c48d532ce92f7f28be1a4f7768`.
- **Depois:** juntar o ramo `hotfix/1.9.2` no `main`, que está na 1.10.0, e sair com uma 1.10.1. A 1.10.0 não tem estes campos.
