# Tesourarias departamentais — política operacional

- Cada igreja possui seus próprios departamentos, responsáveis e caixas.
- Um lançamento financeiro do departamento é salvo UMA VEZ em finance_entries com church_id, department_id e account_id.
- O painel da igreja, o pastor e o contador consultam a mesma movimentação, sem duplicação.
- Os líderes só podem consultar e lançar nos departamentos vinculados em department_access.
- O contador visualiza os dados e comprovantes, sem permissão de editar caixa ou movimentações.
- A tesouraria geral cria o caixa, controla contas, corrige/estorna e bloqueia períodos.
- Saídas lançadas por líderes exigem comprovante (foto, PDF, cupom), arquivado em pasta privada da igreja.
- Entradas/saídas departamentais são subconjuntos dos totais gerais; saldos iniciais dos caixas são patrimônio/saldo, não receita do período.
- Transferências entre caixas não devem ser tratadas como novas receitas/doações; esta tela não cria transferências internas.
- A conferência e classificação contábil final dependem de responsável habilitado e do contador.
