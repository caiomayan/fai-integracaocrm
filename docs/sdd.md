# SDD — Spec-Driven Development

`docs/` é a **fonte da verdade** do projeto. Código só muda depois que a spec correspondente diz o quê e por quê.

## Fluxo
1. **requirements.md** — o quê / para quem (requisitos `RF-xx` e critérios de aceite `CA-xx`).
2. **design.md** — como: contratos, arquivos, decisões (`D-xx`).
3. **tasks.md** — passos executáveis, cada um ligado a RF/CA, com dono e status.
4. Implementar → verificar contra os CA → atualizar status em `tasks.md`.

Mudou de ideia no meio? Atualize a spec primeiro, depois o código.

## Guias
- [Contrato da API (resumo para o CRM)](contrato-api-crm.md)
- [OpenAPI](openapi.yaml)
- [Integração para o CRM](integracao-crm.md)
- [Desenvolvimento (ambiente local e testes)](desenvolvimento.md)
- [Implantação em homologação e produção](implantacao-producao.md)

## Specs
| ID | Spec | Status |
|---|---|---|
| 001 | [Ambiente de desenvolvimento](specs/001-ambiente-dev/spec.md) | ✅ concluída |
| 004 | [Nova tentativa e catálogo de cursos/provas](specs/004-nova-tentativa-e-catalogo/requirements.md) | ✅ concluída (1.8.2) |
| 003 | [Prontidão para produção](specs/003-prontidao-producao/requirements.md) · guia: [implantacao-producao.md](implantacao-producao.md) | ✅ concluída (pronto para homologação) |
| 002 | [Integração CRM ↔ Moodle (Vestibular)](specs/002-integracao-crm/requirements.md) · guia: [integracao-crm.md](integracao-crm.md) | ✅ implementada (aguarda revisão) |

## Pendências externas (validar com Luciano / FAI)
- Configuração de e-mail duplicado (`allowaccountssameemail`) no Moodle da FAI.
- Política de senha do Moodle da FAI (senha = CPF numérico pode ser recusada).
- IDs reais: curso do Vestibular 2027.1 e questionário.
- Login transparente (SSO) do candidato — fora do escopo da 002.
