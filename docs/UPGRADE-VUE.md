# Instalação e atualização da interface Vue

Base inspecionada: pacote fornecido 0.1.219. Esta entrega é uma candidata de evolução em revisão na [PR #217](https://github.com/wkarts/laravel-updater/pull/217), branch `new-ui-16102026` contra `main`. Nenhuma tag, release ou implantação produtiva foi publicada por esta correção.

## Instalação na aplicação hospedeira

Mantenha PHP >=8.2, Laravel 10/11/12 e as dependências operacionais já exigidas pelo Updater (SQLite, Git, permissões, executor, backup e serviços conforme configuração). O cliente HTTP Guzzle 7 é agora uma dependência Composer explícita, necessária também no Laravel 10. Webhooks de saída requerem a extensão cURL e acesso HTTPS ao destino público.

1. Faça backup do código, do estado SQLite do updater e das configurações; preserve o `APP_KEY`.
2. Instale a versão revisada do pacote pelo fluxo Composer/repositório de sua aplicação. Não substitua o `composer.json` da aplicação pelo deste pacote.
3. Execute `php artisan optimize:clear` e reconstrua os caches usados pela instalação (`config:cache`, `route:cache`, quando aplicável).
4. Reinicie workers de fila se houver e confirme que o scheduler Laravel existente executa `schedule:run` por minuto.
5. Abra o prefixo configurado e confira login, permissões, fonte/perfil, simulação, histórico e backups. Para aplicar, confirme explicitamente a ação real.

Os assets de produção já acompanham `resources/dist`. **Não instale Node/npm nem execute build na aplicação hospedeira. Não há publicação manual de assets obrigatória.** São servidos diretamente pelo pacote, com nomes de conteúdo hash e prefixo configurável. O fallback pelos endpoints CSS/JS antigos mantém o carregamento enquanto caches antigos são limpos; rotas novas só ficam disponíveis depois de reconstruir o cache de rotas.

## Arquitetura

- `resources/frontend`: Vue 3, TypeScript e Vue Router, com componentes, páginas, serviços HTTP/tipos e tokens CSS.
- `SpaPresenter`: fronteira de dados com allowlist por página e remoção de segredos.
- `SpaResponseMiddleware`: navegação JSON usando as mesmas rotas, sessão e controles do servidor; erros de interface também usam Vue.
- O criador de views altera o caminho antes da avaliação de Blade. O único shell monta o Vue e carrega bootstrap/arquivos compilados; templates antigos e overrides publicados não executam suas telas. Arquivos antigos foram conservados por compatibilidade de referências externas não disponíveis para inspeção.
- As rotas existentes continuam sendo os handlers de páginas/ações. Não há fallback genérico que capture APIs ou a aplicação hospedeira.
- CSS e dependências são carregados apenas no documento do painel. O modo claro é padrão; a escolha escura é opcional e persistida localmente.

## Personalizações e arquivos Blade antigos

Os nomes das 38 views originais foram conservados para não remover referências que possam existir no host. O provider redireciona sua apresentação para um único shell antes de avaliar os templates: arquivos legados em `resources/views/vendor/laravel-updater` deixam de alterar a interface. Isso inclui layouts, includes e substituições Blade publicadas anteriormente; não há uma interface Blade alternativa em execução. Personalizações visuais antigas precisam ser adaptadas aos componentes/tokens Vue ou aos controles de branding existentes.

Não é necessário publicar CSS/JS nem views para ativar o painel. `php artisan optimize:clear` limpa também as views compiladas antigas. Evite apagar indiscriminadamente personalizações externas: conserve uma cópia para a migração. A distribuição atual contém 42 arquivos Blade físicos (38 originais e quatro invólucros), e o frontend de produção vem compilado em `resources/dist`, incluindo o manifest.

## Prefixos e autenticação

`updater.ui.prefix` continua configurável, inclusive com segmentos (`reviewed/panel`). Os testes executaram esse prefixo. O padrão `_updater` é preservado. Auth própria mantém login, TOTP, recovery, permissões e sessão; quando desabilitada, continua sendo aplicada a pilha configurada pela aplicação hospedeira. As rotas operacionais que já dependiam de autenticação própria continuam com essa disponibilidade; a interface não inventa endpoints ausentes no modo legado do host. Nesse modo, as seções genéricas de perfis, configurações, seeds e usuários administrativos respondem 403 quando o CRUD dedicado não foi registrado, em vez de produzir 500 por rota inexistente. Isso não amplia a disponibilidade histórica dessas operações.

Formulários Vue enviam CSRF, sessão e os nomes de campos reais, inclusive upload, PUT/DELETE por method override e confirmações. O servidor valida cada ação. QR de configuração TOTP é gerado localmente, sem serviços externos.

## Manutenção 503 e acesso ao updater

Tanto a manutenção soft quanto a nativa usam a apresentação Vue do pacote. Na manutenção nativa, a exceção se limita ao prefixo dedicado do updater e seus descendentes, para carregar os assets e permitir o acesso administrativo necessário à recuperação. Rotas da aplicação fora desse prefixo continuam bloqueadas; login, permissões e CSRF continuam obrigatórios dentro do updater. Exceções existentes do middleware do host são preservadas.

Essa compatibilidade foi verificada em Laravel 10/11/12 com `artisan down --render` real, incluindo o arquivo de manutenção que responde antes do bootstrap. O pacote não depende de suporte a `down --except`. Se o host substitui integralmente o middleware de manutenção sem herdar o comportamento do Laravel, ou utiliza outro mecanismo de bloqueio no proxy/servidor web, sua política também deve permitir os assets e o acesso administrativo do prefixo configurado. Uma página de manutenção personalizada fora da namespace do pacote continua sob responsabilidade dessa personalização.

## Mudanças que exigem adoção

Leia [SECURITY-UPGRADE.md](SECURITY-UPGRADE.md) e [API.md](API.md): aplicação real agora exige confirmação explícita; migração exige motivo/ação; credenciais são criptografadas gradualmente; leituras retornam indicadores; comandos de perfis vazios preservam o valor e remoção é separada. A API bloqueia comandos/SQL remotos e exige `confirmation=ATUALIZAR` para aplicação real. Tokens antigos e os dois cabeçalhos continuam aceitos.

URLs de webhook usam HTTPS público na porta 443, sem query/credenciais/redirect; ações/eventos são explícitos. Filas assíncronas usam o worker configurado; com fila síncrona, o scheduler executa as entregas pendentes. Sem scheduler não há promessa de despacho automático. As rotinas reais de atualização, restauração e nuvem dependem da infraestrutura da aplicação.

## Manutenção do pacote

Somente mantenedores precisam de Node >=20.19 (CI usa 22) e npm:

```bash
npm ci --no-audit --no-fund
npm run build
composer install
composer test
```

Versione `resources/dist` completo, incluindo `.vite/manifest.json`. O workflow de frontend verifica que o build é reproduzível e que os arquivos compilados foram incluídos. A matriz de CI existente mantém PHP 8.2–8.5 e Laravel 10/11/12 dentro das combinações previstas pelo projeto.

## Rollback

Antes da migração de credenciais, mantenha uma cópia íntegra do SQLite e das configurações. O código 0.1.219 não entende os valores cifrados novos: reverter somente código depois da migração não basta. Restaure o snapshot de estado/configuração correspondente, preserve o `APP_KEY`, suspenda jobs novos antes de reverter e reconstrua os caches. Restaurar um estado antigo descarta os registros/alterações posteriores ao snapshot; planeje a janela de reversão. As tabelas novas são aditivas; não há remoção destrutiva de tabelas antigas.

## Limites de validação

As evidências executadas estão em `VALIDATION.md`. Instalação produtiva, atualização/restore de uma aplicação cliente, providers Git privados, entrega HTTPS externa, fila externa e armazenamento em nuvem precisam de homologação com credenciais e infraestrutura reais. Nenhuma operação de produção foi executada nesta entrega.

A evolução também corrige o despacho real de jobs no modo de fila: reinicie os workers após atualizar. Fonte/perfil são resolvidos pelos identificadores da execução; uma fonte/perfil excluído falha explicitamente, sem mudar para outra origem. O executor HTTP real conserva a preferência já existente por processo CLI destacado.
