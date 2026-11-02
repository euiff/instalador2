# Migração de escaladeproposito.online para Faith Flow PHP/MySQL

Destino: `escaladeproposito.online`

## Objetivo
Substituir a aplicação AdminIgreja antiga pelo novo Faith Flow em PHP/MySQL usando o atualizador já instalado, sem exigir reinstalação completa pelo cPanel.

## Regras da migração

1. Criar backup dos arquivos atuais antes de qualquer alteração.
2. Não apagar o banco legado.
3. Detectar o AdminIgreja antigo pelas tabelas `accounts`, `visitors` ou `transactions`.
4. Renomear apenas tabelas antigas que colidem com o schema novo para `legacy_adminigreja_<timestamp>_<tabela>`.
5. Criar o schema novo do Faith Flow.
6. Substituir a aplicação somente após o banco novo estar pronto.
7. Manter rollback possível até a validação final.
8. Depois da migração, usar `euiff/faith-flow-pix` como origem oficial das atualizações.

## Fluxo do takeover

AdminIgreja instalado -> atualizador legado -> pacote ponte -> backup -> LegacyTakeover -> Faith Flow PHP/MySQL -> atualizador novo.

## Arquivos sensíveis que não podem ser expostos no GitHub

- credenciais MySQL
- token GitHub da instalação
- chaves PagBank/Evolution/Gemini/TTS
- arquivos enviados pelos usuários

Esses dados devem ficar na configuração local do servidor e/ou banco da instalação.

## Status

- [x] branch `php-mysql` criada
- [x] atualizador PHP com backup e migrations
- [x] rotina segura de takeover do banco legado
- [ ] portar todas as telas e regras do React/Supabase para PHP
- [ ] validar equivalência funcional
- [ ] gerar pacote ponte compatível com o atualizador AdminIgreja 2.6.0
- [ ] publicar takeover no canal `adminigreja-updates`
- [ ] executar atualização no domínio
- [ ] validar e então desativar o legado
