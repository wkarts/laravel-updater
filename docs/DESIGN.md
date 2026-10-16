# Direção visual adotada

As cinco imagens fornecidas pelo usuário são a referência visual desta implementação. Sua composição, paleta fria, azul vivo, hierarquia e densidade administrativa orientam todas as páginas. Menus, dados e indicadores ilustrativos dessas imagens não criam funcionalidades nem resultados no backend.

## Referência canônica

- Fundo cinza-azulado muito claro, superfícies brancas, texto navy e azul vivo nas ações/seleções.
- Sidebar compacta com ícones SVG de traço consistente e grupos Operação, Integridade e Administração.
- Cabeçalho compacto, conta e contexto; sem hero editorial ou identidade visual alternativa.
- Dashboard com estado/versão/última execução/ação; histórico e verificações lado a lado. No celular, verificações precedem o histórico.
- Atualizações com catálogo visual de tags e notas correspondentes em duas colunas; configuração e execução abaixo.
- Integrações com abas API/saída/entrada, tabelas amplas, contexto lateral e criação em diálogo.
- Diagnóstico com resumo, metadados, linha do tempo e eventos técnicos; migrations com integridade/filtros/tabela compactos.

Logos e cores explicitamente configurados pela aplicação são preservados. O cubo SVG azul funciona como símbolo padrão; não substitui um logo fornecido pelo cliente. Indicadores não coletados são identificados como não verificados; não são preenchidos com dados das imagens.

As referências estão em `references/`; a comparação visual está em `DESIGN-CONCEPTS.html`. O estudo inicial foi substituído e está em `design-history/first-pass-concepts.html`, identificado como histórico sem aplicação no produto. Os tokens efetivos estão em `design-tokens.json` e no CSS.

## Componentes e estados

Shell com navegação compacta, cabeçalho/breadcrumb, título/contexto, tabela adaptável, formulário rotulado, confirmação tipada, progresso com etapa, feedback e resultado. Todos devem contemplar carregamento, vazio, sucesso, erro e permissão insuficiente. Notas de release são texto seguro e sempre vinculadas à tag exata.

Desktop: sidebar e área principal. Tablet: grade se adapta. Mobile: menu recolhido, ações acessíveis, formulários em coluna e tabelas empilhadas. Confirmações perigosas explicam o alvo e a consequência; RESTAURAR e ATUALIZAR são entradas deliberadas, não botões ambíguos.

## Evidência inicial

Inspeção da versão anexada: 38 templates Blade, 93 declarações de rotas nomeadas (80 nomes únicos; há blocos alternativos de autenticação), 37 arquivos de teste. Composer declara PHP >=8.2 e Illuminate 10/11/12. Nenhum PHP/Composer existia inicialmente no ambiente; runtimes foram provisionados e os resultados executados estão em VALIDATION.md.

O inventário de rotas dedicado complementa este mapa e distingue destinos, ações, redirecionamentos, includes e arquivos órfãos. Não houve remoção de contrato com base apenas no nome de um template.

A galeria da implementação está em SCREEN-GALLERY.html. Tokens efetivos estão em design-tokens.json e resources/frontend/styles.css. As imagens de referência e a galeria são apresentadas separadamente; a galeria mostra a implementação com fixtures de QA.

## Mapa de telas e componentes implementados

`App.vue` fornece navegação, breadcrumb, tema, carregamento de navegação e erro de carregamento com nova tentativa. O roteador resolve somente destinos conhecidos do updater; os endereços e permissões vêm do Laravel. O mapa completo por nome/método está em ROUTE-INVENTORY.md.

| Destinos | Página Vue | Componentes e controles principais |
|---|---|---|
| Login, desafio 2FA | AuthPage | ActionForm, rótulos, erro de campo e sessão |
| Manutenção 503 e erros 403/404/500 | AuthPage | Identidade, mensagem segura, ação disponível conforme contexto |
| Visão geral e integridade | DashboardPage | Estado real do repositório, tarefas e ações autorizadas |
| Escolha de versão, simulação e aplicação | UpdatesPage | ActionForm, seletor exato, notas com estados, resumo da decisão, confirmação |
| Execuções, detalhe e diagnóstico | OperationsPage | DataTable, ProgressPanel, aprovação com senha e ATUALIZAR |
| Backups, progresso, download e restore | OperationsPage | DataTable, ProgressPanel, ActionForm, RESTAURAR e senha |
| Fontes Git e credenciais | AdminPage | DataTable, formulário, indicador configurada/ausente, substituir/remover |
| Perfis: lista, criar e editar | AdminPage | DataTable, políticas, comandos confiáveis e confirmação |
| Migrations e detalhe de auditoria | OperationsPage | DataTable, motivo e escolha explícita da ação |
| Seeds e reaplicação | OperationsPage | DataTable, ActionForm e resultado |
| Logs e segurança operacional | OperationsPage | Filtros, DataTable, confirmação de ações e diagnóstico |
| Usuários: lista, criar e editar | AdminPage | DataTable, permissões e reset de 2FA com senha |
| Branding, runtime, nuvem, ambiente e tokens | SettingsPage | Abas, ActionForm, validação, indicadores e revelação única |
| Perfil, senha, TOTP e recuperação | ProfilePage | ActionForm, QR local e recuperação de exibição única |
| Documentação API e exemplos | IntegrationsPage | Contratos, autenticação, escopos e OpenAPI |
| Webhooks e histórico | IntegrationsPage | DataTable, allowlists, rotação/teste/repetição e revelação única |

## Estados e adaptação

- **Carregando:** shell na navegação, consulta de notas e ProgressPanel durante polling; não apresentam números ilustrativos como dados reais.
- **Vazio:** DataTable e módulos sem fonte/perfil/notas mostram contexto e próximo passo disponível. Pré-requisitos ausentes bloqueiam o formulário de atualização.
- **Sucesso e falha:** ActionForm apresenta resultado do Laravel; erros de validação aparecem nos campos; falhas de transporte preservam o contexto e permitem nova tentativa.
- **Sem permissão:** navegação/ações são filtradas na interface e as rotas mantêm validação no servidor. Resposta 403 usa a mesma camada Vue.
- **Confirmação:** diálogo interno com cancelamento; ações reais exigem as confirmações específicas. Segredos digitados não são armazenados no estado Vue de formulários e são limpos após sucesso.
- **Listas:** busca/ordenação quando úteis; em celular cada linha torna-se uma estrutura empilhada com rótulos e ações visíveis. O menu móvel oferece fechamento, Escape, retorno de foco e contenção do foco enquanto aberto.

Os tokens anteriores foram substituídos para acompanhar as imagens canônicas. `design-tokens.json` e o CSS são a referência dos valores finais, incluindo raios e contraste; não se deve inferir um segundo sistema visual dessas pequenas calibrações.
