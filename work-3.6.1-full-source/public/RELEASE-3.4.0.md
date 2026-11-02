# Igreja Master 3.4.0 — Navegação e cadastros

## O que mudou

- Menu agrupado em Visão geral, Pessoas e cadastros, Agenda da igreja, Financeiro, Comunicação e Administração. Cada perfil vê somente as áreas permitidas.
- Busca de funções pelo nome, disponível no menu e pelo atalho `/`.
- Botão global **Novo cadastro**, com escolha do tipo de registro.
- Nos módulos de cadastro, listas e filtros ocupam a área principal. **Novo cadastro** e **Editar** abrem uma janela flutuante.
- Janelas com foco de teclado, rolagem interna, fechamento por Esc e confirmação antes de descartar alterações.
- Recuperação do preenchimento após erros de validação. Senhas, tokens e chaves não são guardados nos rascunhos. Arquivos precisam ser selecionados novamente.
- Tesouraria dividida em abas: Movimentações, Comprovantes, Recibos, Contas a pagar, Contas e orçamento, Conferência e fechamento.
- Painel inicial com ações diretas e indicadores de atenção.
- Tamanhos de texto, contraste, botões, tabelas e espaçamentos padronizados para computador e celular.

## Áreas com formulário flutuante

Membros, visitantes, congregações, eventos, cultos e reuniões, acessos de usuários, necessidades, acompanhamento pastoral, departamentos, patrimônio, grupos, comunicados, enquetes, rifas, relógios de oração, inscrições em eventos e pedidos de oração. A Tesouraria tem janelas próprias para cada ação de cadastro.

Configurações extensas continuam em páginas próprias. A melhoria visual não substitui os recursos existentes nem altera os cálculos financeiros.

## Atualização

Use o menu **Administração → Atualizações** para instalar a versão disponível no canal existente. O acesso ao atualizador continua sujeito à permissão Master.

Para aplicação manual, faça backup dos arquivos e do banco, extraia o ZIP na mesma pasta do Igreja Master e preserve `config/database.php` e `storage/`. Não execute o instalador para atualizar uma instalação existente.

O pacote é cumulativo: inclui a 3.3.0, suas dependências e as correções anteriores de documentos, enquetes e integração com a Evolution API. Não altera o DNS e não modifica registros do sistema de referência.

## Validação

A verificação usa uma cópia descartável da versão cumulativa 3.3.0, dados fictícios e navegador Chromium. Os testes cobrem telas no computador e no celular, abertura e fechamento das janelas, foco de teclado, limites de largura, validação, salvar e editar membro, retenção após erro, descarte de alterações e abas financeiras. A instalação na hospedagem real é uma etapa separada.
