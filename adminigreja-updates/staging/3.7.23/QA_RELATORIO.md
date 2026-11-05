# Igreja Master — relatório de QA da atualização 3.7.23

Data: 2026-11-04 (Brasil), execução automatizada no GitHub Actions UTC.
Ambiente: Ubuntu 26.04, PHP 8.2, MariaDB 11.4, Node.js para sintaxe JS.
**Todos os dados usados na auditoria são fictícios. Nenhum registro de igreja real foi alterado.**

## O que foi efetivamente validado

| Área | Evidência automatizada | Resultado |
|---|---|---|
| Integridade do pacote | SHA-256 do arquivo compactado e dos 140 arquivos | APROVADO |
| Compatibilidade PHP | 107 arquivos PHP, `php -l` | APROVADO |
| Sintaxe JavaScript | 6 arquivos JS, `node --check` | APROVADO |
| Auditoria de rotas | 52 rotas PHP; autenticação exigida e POST com CSRF ou guarda hash | APROVADO como análise de código |
| Perfis e isolamento | Master, pastor/admin, secretário, tesoureiro, contador, líder, participante; acessos separados por igreja | APROVADO na suíte de serviço |
| Membros e congregações | cadastro fictício, vínculos, liderança, arquivar/excluir com bloqueios e transferência em massa | APROVADO na suíte de serviço |
| Liderança por departamento | membro comum não vira líder; promoção/demissão, tesoureiro delegado, revogação e caixa restrito | APROVADO na suíte de serviço |
| Tesouraria Geral | receitas, saídas, importação CSV, recebimentos e bloqueio de caixa departamental | APROVADO na suíte de serviço |
| Caixas dos ministérios | entradas/saídas pelo líder, prestação de contas e consolidação sem duplicação | APROVADO na suíte de serviço |
| Exemplo contábil realista | dízimo geral R$ 100 de membro de Jovens + oferta Jovens R$ 50 = R$ 150 consolidado | APROVADO |
| Contador | acesso somente-leitura, anexos, fechamento, solicitações e segregação por igreja | APROVADO nas regressões |
| Recibo PDF | documento para assinatura em papel, assinatura na tela em imagem JPEG e PDF legível no parser `pdfinfo` (quando disponível) | APROVADO nas regressões |
| Tutorial do sistema | 25 módulos, 144 aulas, progresso por usuário, 17 guias visuais | APROVADO nas regressões |
| Importação Enuves | XLSX fictício, prevenção de duplicados, vínculos, lideranças e isolamento | APROVADO nas regressões |
| PIX: totais | 1007 registros de teste, 2 igrejas, filtros, 1.000+ registros; pendentes não são confirmados | APROVADO |
| PIX: integridade | lançamento existente não deve duplicar, edição de outro tenant rejeitada, validação de valores e tipos | APROVADO em verificações de código e suíte de agregação |
| Gestão 360 | perfis, indicadores, tarefas/eventos, links condicionais | APROVADO nas regressões |

## Erros detectados e corrigidos nesta atualização

1. **Pagamentos PIX — totais indefinidos**: a tela mostrava `$total`, `$confirmed` e `$pending` sem calcular esses valores. Agora as somas são obtidas diretamente do banco, por igreja, status e período, sem depender das primeiras 1.000 linhas mostradas.
2. **Reconfirmação PIX — risco de duplicidade**: o código confiava apenas em `ON DUPLICATE KEY UPDATE`; agora ele consulta e bloqueia o pagamento e o lançamento associado antes de atualizar ou inserir, recusando registros múltiplos preexistentes em vez de acrescentar outro.
3. **Edição de PIX inexistente ou de outra igreja**: agora apresenta erro de registro não encontrado. Também foram reforçadas as validações da criação manual.

## Limites da auditoria — não anunciar “100% testado”

- O canal de atualização distribui **140 arquivos** de alterações, mas **não o núcleo completo** instalado no cPanel. Por exemplo, o `bootstrap.php`, o login original e várias rotas legadas não fazem parte desse pacote. Assim, não foram executados testes completos de navegação browser contra uma cópia atual da instalação real.
- Não foram enviados WhatsApps reais, e-mails de recuperação de senha ou cobranças, nem acionados CRON de produção. A API Evolution e o SMTP exigem teste separado com credenciais e ambiente de homologação.
- O OCR de imagens reais, o upload de arquivos via navegador, todas as modalidades de certificados/cartas e a impressão nos dispositivos dos usuários ainda exigem testes integrados. Parte do PDF de recibo foi efetivamente validada com dados sintéticos.
- **Acesso automático do membro e recuperação de senha por e-mail:** o serviço de ativação ainda inclui fluxo de ativação explícita, e o mecanismo do login pertence ao núcleo legado. O comportamento ponta a ponta solicitado precisa ser confirmado numa instalação de homologação antes de qualquer afirmação de conclusão.
- O HTTPS do domínio deve ser conferido no cPanel; uma auditoria PHP não instala certificado SSL.

## Próxima validação necessária para aprovação final em produção

Clonar **arquivos completos + estrutura do banco**, sem membros reais, para uma homologação isolada. Rodar autenticação e recuperação de senha em navegador, todos os formulários (incluindo nota/cupom com foto), uploads e impressão, API Evolution/SMTP em modo de testes, e migração incremental desde a versão instalada. Só depois aprovar implantação e aferir o comportamento diretamente no cPanel.

**Conclusão:** lançamento 3.7.23 aprovado nos testes automatizados descritos acima. **Ainda não certificado como “sistema inteiro 100% sem falhas”**, pois os fluxos externos e a instalação completa não foram executados.
