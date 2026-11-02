# Igreja Master 3.3.0 — Pessoas e patrimônio

Esta atualização cumulativa acrescenta recursos ao Igreja Master e inclui as correções publicadas até 3.2.11. A comparação foi feita em 24/10/2026, após acesso autorizado à conta do usuário em Gestão de Igreja. A implementação é própria e usa os cadastros, permissões e layout existentes do Igreja Master.

| Área | Situação na base 3.2.2 | Entrega 3.3.0 |
| --- | --- | --- |
| Visitantes | Sem módulo dedicado de acolhimento | Primeira visita, responsável, próximo contato, situação, observações e vínculo opcional a membro existente |
| Cuidado pastoral | Pedidos de oração e aconselhamento pelo bot existentes | Registros de visitas, ausências percebidas, atendimento e discipulado, com histórico e retornos |
| Departamentos | Campo de texto no cadastro | Cadastro de ministérios, liderança, encontros e participação em vários departamentos |
| Patrimônio | Tesouraria já existente | Inventário, etiqueta, valor de aquisição, local, condição e histórico de manutenção |
| Painel | Indicadores gerais e financeiros | Retornos pastorais, contatos de visitantes, aniversariantes de hoje e manutenção prevista |
| Pessoas e crescimento | Lista de membros e relatórios existentes | Entradas mensais nos últimos 12 meses e aniversariantes do mês |

## Uso

- Acesse as novas opções no menu lateral ou nos atalhos do painel.
- Cuidado pastoral: administrador, proprietário e pastor; secretaria e tesouraria não recebem esse acesso por padrão.
- Visitantes, departamentos e pessoas/crescimento: permissão Secretaria.
- Patrimônio: permissão Tesouraria.
- Impressão e salvamento em PDF estão disponíveis pelo diálogo de impressão do navegador nos novos módulos.
- As telas de visitantes, cuidado pastoral e inventário exibem até 500 resultados por consulta. Os limites são indicados na tela.

## Atualizar a instalação existente

1. Faça backup dos arquivos e do banco existentes.
2. Extraia o ZIP de atualização na mesma raiz do Igreja Master, substituindo os arquivos do aplicativo.
3. Preserve `config/database.php` e a pasta `storage/`: o pacote não inclui credenciais nem uploads.
4. Abra o painel. A migration `024_people_and_assets.sql` cria as tabelas adicionais automaticamente.
5. Não execute `install.php` e não crie outro banco para esta atualização.

O ZIP não altera o DNS nem instala automaticamente no cPanel. A versão também é disponibilizada pelo canal de atualizações existente; a instalação depende de iniciar a atualização no painel.

## Limites e preservação

- Documentos, WhatsApp/Evolution, enquetes, rifas, oração, eventos, congregações e tesouraria permanecem na base existente. Não houve importação de dados do sistema de referência.
- Ausência percebida é registrada pela liderança. Não há inferência automática de frequência.
- O relatório de entradas não representa crescimento líquido: o sistema ainda não registra a data histórica de cada desligamento.
- Patrimônio apresenta valor de aquisição. Não calcula depreciação nem contabiliza manutenção automaticamente na Tesouraria.
- Kids como departamento pode ser organizado aqui. Check-in infantil com retirada autorizada, cursos, favoritos, aprovação de autocadastro e projetos da diretoria não fazem parte desta entrega.
- O autocadastro público existente foi preservado com seu comportamento anterior.

## Validação

Testes aprovados em 24/10/2026: validação PHP/MySQL existente e integração com a base publicada 3.2.11. Foram verificados migrations idempotentes, separação de dados por igreja, permissões, validações, histórico pastoral, visitantes, departamentos, patrimônio, formulários HTTP e CSRF. A instalação real no cPanel ainda não foi executada.
