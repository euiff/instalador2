# Igreja Master PHP/MySQL 3.0.0

Release de produção para takeover do sistema legado em `escaladeproposito.online`.

- PHP 8.1+ e MySQL/MariaDB.
- Instalador e primeiro acesso Master protegidos.
- Migração do AdminIgreja legado sem apagar o banco antigo.
- Multi-tenant com isolamento por igreja.
- Secretaria, membros, eventos, financeiro, relatórios e transparência.
- Atualização automática com backup, staging, SHA-256 e migrations versionadas.
- Bridge de takeover compatível com o atualizador legado.

Antes do corte definitivo: realizar backup integral do domínio e banco e validar integrações externas (Evolution API, Gemini, ElevenLabs e Bible API) no ambiente real.
