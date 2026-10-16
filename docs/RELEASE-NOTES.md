# Candidata de evolução após 0.1.219

## Interface

Interface administrativa reconstruída em Vue 3/TypeScript/Vue Router: acesso/2FA/perfil, dashboard, atualização/simulação/aprovação, execuções/diagnósticos, backups/restauração, fontes, perfis, migrations, seeds, logs, segurança, usuários, branding/runtime/ambiente/nuvem, tokens, documentação API, webhooks, manutenção503 e erros. Claro padrão e tema escuro opcional.

Inventário: 38 templates de origem e 93 declarações de rotas nomeadas (80 nomes únicos). Includes/órfãos/redirecionamentos foram classificados; telas antigas não são avaliadas no fluxo de apresentação. O invólucro só monta o Vue. Assets compilados acompanham o pacote Composer.

## Comportamento e segurança

- Simulação e aplicação real têm semânticas distintas, confirmação explícita e testes de regressão.
- Credenciais antigas não são preenchidas nem retornadas à interface; substituição/remoção são separadas. Proteção em repouso com a chave Laravel.
- TOTP não usa geradores externos de QR; recuperação tem exibição única.
- Aprovar exige permissão de atualização, senha e simulação concluída (incluindo o status real `DRY_RUN` do kernel); reexecução de migration exige motivo/ação explícitos.
- API legada mantém caminhos/cabeçalhos/token legado, com escopos novos, revogação/rotação, limites, idempotência e OpenAPI. Endurecimentos de payload e confirmação exigem adoção nas integrações.
- Webhooks recebidos/saída têm assinatura, controle de replay, allowlist, persistência, fila/scheduler, SSRF, timeouts e retries auditáveis.
- Notas e metadados são consultados para a fonte e tag exatas, com cache e ausência explícita.
- O plano de simulação preserva o alvo solicitado. Para fonte/tag/branch alternativa, não inventa commit nem distância de commits; a comparação fica explicitamente indisponível quando não verificada.
- Manutenção nativa mantém assets e acesso autenticado ao prefixo do updater; o restante da aplicação continua em 503.

## Compatibilidade e adoção

PHP >=8.2, Laravel 10/11/12 mantidos. Guzzle passa a ser dependência explícita para o cliente HTTP também no Laravel 10. Compactação de perfil exibe ZIP, que era o valor efetivamente imposto pelo backend; não promete formatos que eram ignorados.

Consulte `UPGRADE-VUE.md`, `SECURITY-UPGRADE.md`, `API.md`, `WEBHOOKS.md` e `VALIDATION.md`. Nenhuma tag/release remota foi publicada. Versão sugerida para revisão: **0.2.0**, sujeita à homologação operacional e aprovação do mantenedor.

## Executor em fila

Jobs de atualização/rollback passam a usar o contrato ShouldQueue. A fonte é resolvida por execução, impedindo que a seleção global substitua uma fonte explicitamente solicitada. O perfil confiável fornece os comandos ao worker; parâmetros remotos não os substituem. Decisões explícitas de simulação são preservadas e seeds não são ativados implicitamente.

## Alinhamento visual às referências

Paleta fria e azul vivo; navegação agrupada com ícones SVG; dashboard compacto com histórico/verificações; catálogo e notas em duas colunas; integrações por abas com configuração em diálogo; diagnóstico com timeline e eventos técnicos e auditoria de migrations compacta. Logos/cores configurados, contratos, confirmações e controles de segurança são mantidos. A comparação visual distingue dados ilustrativos de informações reais do backend.
