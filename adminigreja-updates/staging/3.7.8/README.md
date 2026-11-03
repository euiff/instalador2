# Igreja Master 3.7.8 — acompanhamento pastoral (em desenvolvimento)

**Status: staging, não publicar como atualização automática.**

Esta etapa inicial adiciona uma estrutura SQL independente e um serviço PHP para casos pastorais por igreja, responsáveis, agendamento de retorno, histórico de contatos, notas confidenciais e auditoria.

## Segurança e integração pendentes
- Ligar as operações à autenticação e à matriz de permissões já existente, sem aceitar church_id do navegador.
- Confirmar chaves e nomes atuais das tabelas de membros, visitantes e usuários no pacote 3.7.7.
- Validar que o responsável pertence à igreja indicada.
- Restringir acesso a notas confidenciais; não expô-las em portal de membros, CSV comum ou mensagens WhatsApp.
- Adicionar telas, filtros, notificações e testes de integração com dados reais anonimizados.
- Fazer backup e testar a migração 040 em homologação antes de adicionar ao manifesto de release.
- Não alterar o canal latest.json enquanto os passos acima estiverem pendentes.

## Testes feitos
PHP syntax lint local no serviço, e verificação estrutural dos nomes e índices SQL.
