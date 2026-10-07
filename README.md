# Integração CRM ↔ Moodle — Vestibular FAI

Serviço que conecta o CRM ao Moodle da FAI. O CRM **cadastra o candidato**, **matricula na prova do vestibular** e depois **consulta a nota e a conclusão**, sem ninguém precisar operar o Moodle manualmente.

## Como funciona

```
CRM ──▶ cadastra o candidato ──▶ matricula no Vestibular ──▶ candidato faz a prova no Moodle
 ▲                                                                     │
 └──────────────────── consulta nota e conclusão ◀─────────────────────┘
```

| O CRM faz | O serviço responde |
|---|---|
| Cadastrar candidato | o identificador do candidato no Moodle |
| Matricular no curso do Vestibular | confirmação (o papel padrão é *estudante*) |
| Consultar resultados | lista de candidatos com **nota** (0–1000) e **concluído** (sim/não) |

## Exemplo

```http
POST /local/faicrm/rest_json.php?wsfunction=local_faicrm_get_resultados_vestibular
Authorization: Bearer <token>
Content-Type: application/json

{ "courseid": 10 }
```

```json
[
  { "username": "12345678900", "firstname": "Maria", "lastname": "da Silva",
    "email": "maria@email.com", "courseid": 10, "nota": 760, "concluido": true }
]
```

Os erros vêm prontos para exibir na tela, em português e com o status HTTP adequado:

```json
{ "message": "Já existe um candidato com este e-mail." }
```

## Destaques

- **JSON de ponta a ponta:** fácil de integrar com qualquer CRM ou plataforma.
- **Seguro:** acesso por token exclusivo da integração, com permissões mínimas, e nenhum dado interno exposto nos erros.
- **Nativo do Moodle:** usa os web services oficiais; o plugin só adiciona o que faltava.
- **Validado:** fluxo completo, erros e segurança testados (Moodle 4.5 · PHP 8.1).

## Documentação

- [Guia de integração para o CRM](docs/integracao-crm.md): chamadas, parâmetros, erros e implantação.
- [Desenvolvimento](docs/desenvolvimento.md): ambiente local com Docker e testes com o Bruno.
- [Especificações](docs/sdd.md): requisitos e decisões do projeto.
