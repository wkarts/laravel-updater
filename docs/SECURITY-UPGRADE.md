# Adoção dos controles de segurança

O backend continua responsável pela autorização, validação e execução. As mudanças abaixo endurecem solicitações perigosas sem remover nomes de rotas ou substituir os serviços operacionais existentes.

## Simulação e atualização real

Na ação web de atualização, informe `action=simulate` ou `action=apply`. `action=simulate` e `dry_run_before=true` sempre executam somente uma simulação, mesmo quando enviados juntos de `action=apply`. A ausência de `dry_run_before` nessa ação mantém a opção segura de simular.

Para aplicação real direta, envie `action=apply`, `dry_run_before=false` e `confirm_apply=ATUALIZAR` após apresentar o alvo e as consequências ao operador. A aprovação de uma simulação exige `confirm_apply=ATUALIZAR`, senha válida do usuário, simulação concluída (`DRY_RUN`, ou registro legado `success` com `dry_run=true`) e registro de aprovação pendente na sessão. A fonte e o perfil usados na simulação são reativados antes da execução aprovada.

A rota web legada de trigger também respeita `action=simulate`, `dry_run`, `dry_run_before` e a opção de simulação do perfil. Quando a operação for real, exige `confirm_apply=ATUALIZAR`. Comandos pré/pós atualização vêm do perfil confiável no servidor; parâmetros da requisição não os substituem. Os requisitos próprios da API são descritos em [API.md](API.md).

Aprovar uma atualização exige `updates.run`, inclusive quando o usuário possui `runs.view`. Operações de segurança exigem `settings.manage`, mantendo as verificações administrativas específicas. O comportamento legado de administradores sem lista granular de permissões e autenticação controlada pelo host permanece.

## Reexecução de migration

`POST /migrations/reapply` mantém a rota e passa a exigir:

- `migration`: identificador da migration existente.
- `reason`: motivo obrigatório, com espaços externos removidos e de 3 a 1.000 caracteres.
- `action_type`: escolha explícita de `queue` ou `run_now`.

Não existe mais seleção implícita de execução imediata quando `action_type` está ausente. Requisições inválidas não enfileiram nem executam migrations. Consumidores próprios dessa ação web devem incluir os dois campos; a interface Vue já os solicita.

## Credenciais e 2FA

Credenciais Git, segredos de armazenamento em nuvem, parâmetros sensíveis gravados como overrides e segredos TOTP usam a criptografia configurada pelo Laravel hospedeiro. Registros antigos em texto são protegidos ao serem utilizados, preservando seu valor operacional. A atualização gradual utiliza comparação do valor original para evitar sobrescrever uma rotação simultânea.

Preserve o `APP_KEY` nos backups, restaurações e transferências de ambiente. Trocar essa chave sem manter uma forma de recuperar os dados impede a leitura das credenciais já criptografadas. Credenciais novas não são gravadas sem um serviço de criptografia válido.

Leituras de interface mostram indicadores de configuração. Campos de credencial vazios mantêm o segredo existente; substituição e remoção são ações separadas. Comandos confiáveis de perfis também permanecem quando seus campos são enviados vazios; sua remoção exige controle explícito. Não coloque credenciais na URL de uma nova fonte: utilize os campos protegidos.

O segredo TOTP já habilitado não é mostrado novamente nem reutilizado para gerar QR. Uma nova configuração usa um segredo de inscrição, e o QR é gerado localmente pela interface. Nenhum serviço externo recebe o segredo. Desabilitar/redefinir 2FA exige a senha atual; redefinição administrativa mantém a confirmação por senha administrativa. Códigos de recuperação novos são exibidos uma única vez.

Restauração de backup continua exigindo a palavra `RESTAURAR` e a senha válida do usuário. Confirmação digitada e 2FA da manutenção permanecem nas ações existentes.

## Registros e diagnóstico

Novos registros de execução, erros, auditoria e logs de arquivo são higienizados antes da persistência. URLs HTTP com credenciais, inclusive autenticação somente por token, são mascaradas nos resultados e mensagens de erro dos processos. O exportador de diagnóstico mantém a higienização de registros históricos. A atualização não reescreve arquivos de log antigos; aplique a política de retenção da instalação.

## Limites do plano de simulação

A simulação não prepara nem modifica o repositório para obter uma comparação de outra fonte/tag/branch. O plano registra exatamente a referência solicitada e apresenta commit não verificado e distância indisponível nesse caso. As etapas/comandos listados descrevem a estrutura da pipeline; sua execução real depende das políticas do perfil no servidor. Não se deve interpretar essa lista como comandos executados pela simulação.
