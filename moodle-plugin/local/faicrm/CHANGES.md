# Histórico de versões — local_faicrm

## 1.7.1 (2026100801)
- Código no padrão do Moodle Code Checker (moodle-cs): sem erros nem avisos. As classes do adaptador foram para `classes/rest_json/` (`handler`, `server`, `conflict_exception`); o comportamento da API não muda.
- `cli/configurar.php`: `--validade-dias` vale só para o token (exige `--gerar-token`, de 1 a 3650 dias). A autorização do `ws_crm` no serviço fica sempre sem validade: no Moodle 4.5 uma validade ali bloqueia todas as chamadas (403). `--ip` é normalizado e há aviso para faixas `/0`.
- Senha do `ws_crm` (criada uma vez, nunca exibida) passa a vir de `random_string()` (CSPRNG, 40 caracteres + sufixo para políticas de senha); o `generate_password()` do core gerava palavra + dígito + palavra quando a política de senha está desligada.
- `cli/verificar.php`: FALHA se a autorização do `ws_crm` tiver validade; AVISO para capabilities além das necessárias, papéis atribuíveis além de student e bloqueio de conta por tentativas desligado.
- `scripts/empacotar-plugin.sh` (fora do plugin): recusa links simbólicos, arquivos ocultos, de token/segredo/log e tipos fora do padrão de plugin.

## 1.7.0 (2026100800)
- Provedor de privacidade (`null_provider`): o plugin não guarda dados pessoais.
- `cli/configurar.php` (configuração idempotente: web services, REST, conclusão, papel `integracaocrm`, usuário `ws_crm`, autorização no serviço, token opcional) e `cli/verificar.php` (diagnóstico OK/FALHA/AVISO).
- Lógica de configuração na classe `local_faicrm\setup\configurador`.

## 1.6.1 (2026100609)
- Matricular só devolve 409 se o candidato já tem matrícula **manual ativa** (suspensa é reativada; outro método não bloqueia).

## 1.6.0 (2026100608)
- Matricular quem já está matriculado devolve 409; desmatricular quem não tem matrícula manual devolve 404 (tudo ou nada em lote).
- Resultados: `porpagina` deixa de ser parâmetro (fixo em 100).
- A operação também pode ir no caminho: `/rest_json.php/<função>`.

## 1.5.1 (2026100607)
- Filtros `dataprovade` e `dataprovaate` aceitam só `AAAA-MM-DD`.

## 1.5.0 (2026100606)
- Resultados: `datamatricula`, `dataprova` e `dataconclusao` (ISO 8601 com fuso) e filtro por data da prova.

## 1.4.0 (2026100605)
- Resultados paginados (`pagina`, `porpagina`; resposta com `total`, `totalpaginas`, `candidatos`); consultas em lote.

## 1.3.0 (2026100604)
- Erros do adaptador no formato `{"message": "..."}` em português, com status HTTP coerente e `X-Request-Id`.

## 1.2.0 (2026100603)
- Matrícula sem `roleid` usa o papel `student`; retorno nativo `null` vira HTTP 204.

## 1.1.0 (2026100601)
- Adaptador JSON `rest_json.php` (corpo JSON e `Authorization: Bearer`).

## 1.0.0 (2026100600)
- Primeira versão: função `local_faicrm_get_resultados_vestibular` (nota e conclusão) e serviço `crm_vestibular_fai`.
