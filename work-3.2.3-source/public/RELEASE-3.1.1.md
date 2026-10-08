# Igreja Master 3.1.1

## Automações e cron
- Novo módulo Automações no painel.
- Indicador visual de saúde do cron do cPanel.
- Histórico privado de execuções manuais por igreja.
- Botão seguro "Executar agora" para testar somente a igreja selecionada.
- Comando absoluto do cron exibido na própria tela para copiar no cPanel.
- Fila visual de:
  - comunicados agendados;
  - eventos com lembrete;
  - rifas próximas;
  - relógio de oração;
  - enquetes.
- Registro de duração, sucesso e erro das execuções.
- Execução global do cron separada dos dados privados de cada igreja.
- Cron HTTP continua bloqueado sem CRON_KEY.
- Execução CLI permanece indicada para cPanel.

## Segurança multi-tenant
- Execução manual limitada à igreja ativa.
- CI valida que uma igreja não processa campanhas agendadas de outra.
- Resultado agregado do cron global não é exibido aos clientes individuais.

## Banco
- Nova migration 017_automation_runtime.sql.
- Nova tabela automation_runs para diagnóstico e auditoria.
