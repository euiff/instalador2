# Igreja Master 3.1.0

## Visual e navegação
- Congregações redesenhadas em cards modernos.
- Resumo de membros, grupos e eventos por congregação.
- Formulário de congregação separado do painel principal.
- Novo menu Grupos da Igreja.
- Novo menu Comunicados.
- Filtros por congregação em Membros, Eventos e Grupos.

## Grupos da Igreja
- Cadastro central de grupos WhatsApp/Evolution.
- Vínculo por congregação.
- JID da Evolution.
- Link de convite e responsável.
- Sincronização automática dos grupos pela Evolution API.
- Mensagem de teste por grupo.
- Finalidades configuráveis:
  - comunicados;
  - eventos;
  - rifas;
  - relógio de oração;
  - enquetes;
  - devocional diário.
- Importação automática dos grupos antigos salvos em whatsapp_group_id.

## Comunicação
- Tela de Comunicados.
- Envio imediato para um ou vários grupos.
- Agendamento de comunicados.
- Histórico de entregas e erros.
- Cron processa comunicados agendados.

## Eventos
- Seleção de grupos no cadastro.
- Convite automático ao criar.
- Lembrete automático para os mesmos grupos.
- Mantido envio individual existente para membros.

## Rifas
- Seleção de grupos.
- Divulgação automática ao criar.
- Lembrete automático antes do sorteio.
- Resultado enviado aos grupos após sorteio.
- Reserva pelo WhatsApp limitada aos grupos escolhidos.

## Relógio de Oração
- Seleção de grupos.
- Convite automático.
- Lembrete para grupos.
- Mantidos lembretes individuais.
- Inscrição pelo WhatsApp limitada aos grupos escolhidos.

## Enquetes
- Seleção dos grupos destinatários.
- Envio pelo cron aos grupos configurados.
- Fallback para membros quando nenhum grupo estiver disponível.

## Devocional
- Usa o novo cadastro central de grupos.
- Respeita horário por congregação.
- Preserva fallback para configuração antiga.

## Segurança e compatibilidade
- Isolamento entre grupos/congregações validado por CI.
- Grupos antigos preservados/importados.
- Atualizador público e migrations automáticas continuam ativos.
