# Changelog · Igreja Master

## 3.2.10

- Removidas do menu do WhatsApp as opções 5 (Falar com a Igreja) e 9 (Aconselhamento Pastoral).
- Certificados passam a ser gerados em A4 paisagem, inclusive quando enviados pelo WhatsApp.
- Cartas e declarações permanecem em A4 retrato.
- Enquetes passam a usar o componente nativo de enquete do WhatsApp via Evolution API sendPoll.
- Participantes votam tocando diretamente nas opções e consultam barras e “Ver votos” no próprio WhatsApp.
- Envio imediato e agendado usam o mesmo formato nativo.
- IDs das mensagens de enquete são registrados por grupo para rastrear a enquete original.

## 3.2.9

- Enquetes passam a ser votações reais no WhatsApp.
- Participantes votam respondendo VOTO 1, VOTO 2 e assim por diante no grupo.
- O sistema identifica o participante e salva um único voto por pessoa/enquete.
- Se a pessoa votar novamente, o voto anterior é atualizado em vez de duplicado.
- Painel passa a mostrar total de votos, percentuais por opção e lista de votantes.
- Enquetes expiradas deixam de aceitar votos.
- Enquetes agendadas também passam a enviar instruções corretas de votação.

## 3.2.8

- Unificado o gerador de documentos do painel e do WhatsApp.
- Certificados, cartas, declarações e carteirinha passam a usar o mesmo template central.
- PDFs enviados pelo WhatsApp passam a usar o visual moderno do painel, com logo da congregação, assinatura, QR e autenticação.
- Download PDF do painel também passa pelo mesmo renderizador usado no WhatsApp.
- Adicionado motor HTML→PDF e QR local ao pacote para evitar diferenças entre navegador e bot.

## 3.2.7

- Corrigido o envio de enquetes para grupos do WhatsApp: “Enviar agora” passa a executar o envio de verdade.
- Enquetes só são marcadas como enviadas quando pelo menos um grupo recebe a mensagem.
- Falhas de envio passam a ficar visíveis como “Erro de envio”.
- Adicionado botão de reenviar enquete.
- Tela de criação de enquetes redesenhada em etapas: pergunta, opções, grupos e momento do envio.
- Opções agora possuem campos separados e mais claros, com mínimo de 2 e máximo de 8 respostas.
- Mantido o envio agendado pelo cron, agora com tratamento correto de falhas.

## 3.2.6

- Certificados redesenhados com moldura azul/dourada, nome em destaque, selo visual, QR Code e autenticação.
- Cartas e declarações redesenhadas com cabeçalho institucional, tipografia melhor, assinatura, QR Code e bloco de autenticidade.
- Documentos passam a usar primeiro a logo da congregação e, na falta dela, a logo geral da igreja.
- Assinatura usa primeiro a assinatura da congregação e, na falta dela, a assinatura geral da igreja.
- Mantidas as funcionalidades de geração, impressão, PDF e verificação existentes.

## 3.2.5

- Corrigidas as permissões dos arquivos públicos enviados para logo de congregação.
- Novos uploads de logo ficam acessíveis corretamente pelo navegador.
- Ao abrir a edição da congregação, o sistema tenta reparar automaticamente a permissão da logo já enviada.
- Mantidos os ajustes da 3.2.4 para Naturalidade e uso da logo da congregação na carteirinha.

## 3.2.4

- Congregações agora possuem upload de logo própria em JPG, PNG ou WebP.
- A carteirinha usa primeiro a logo da congregação do membro; se não houver, usa a logo geral da igreja.
- Removido o campo Nacionalidade da carteirinha.
- Adicionado o campo Naturalidade ao cadastro e edição de membros.
- Mantidas as demais funcionalidades e o layout profissional da carteirinha introduzido na 3.2.3.

## 3.2.3

- Nova carteirinha de membro em formato frente e verso, inspirada em credencial PVC profissional.
- Foto, logo da igreja, nome, cargo/função, CPF, RG, estado civil, congregação, data de ingresso e emissão na frente.
- Filiação, data de batismo, nacionalidade, naturalidade, departamento, pastor/responsável e endereço da igreja no verso.
- QR Code aponta para a verificação de autenticidade já existente no sistema.
- Impressão preparada em tamanho aproximado de 85,6 × 54 mm, preservando os demais documentos e funcionalidades existentes.

# Changelog · Igreja Master

## 3.2.1

- Fechamento de caixa diário ou mensal, consolidado ou por conta financeira.
- Cálculo automático de saldo inicial, entradas, saídas e saldo final do período.
- Histórico de fechamentos com responsável e observações.
- PDF e impressão profissional de cada fechamento de caixa.
- Relatório geral da Tesouraria agora mostra saldo inicial e saldo final, além de entradas e saídas.
- Teste automático valida o fechamento com saldo inicial de R$ 100, entrada de R$ 250, saída de R$ 80 e saldo final de R$ 270.

## 3.2.0

- WhatsApp reorganizado: horários, pedido de oração, Bíblia e planos de leitura, eventos e inscrições, contato, documentos, cadastro, edição de dados e aconselhamento pastoral.
- Bíblia restaurada com leitura de capítulos e planos automáticos pelo WhatsApp e painel.
- Documentos eclesiásticos em PDF pelo painel e WhatsApp, com código de verificação.
- Relógio de Oração, Rifas, Eventos e Cultos com mensagens explicativas, cartaz/folder, reenvio, PDF e impressão.
- Tesouraria ampliada com livro caixa, entradas, saídas, documentos fiscais/comprovantes, recibos numerados, contas a pagar, orçamento, conciliação e relatórios.
- Uploads separados por igreja e documentos financeiros protegidos.
- Formulários e módulos principais modernizados para navegação mais clara.
- Pagamentos PIX mantidos apenas para compatibilidade, fora da navegação financeira principal.
