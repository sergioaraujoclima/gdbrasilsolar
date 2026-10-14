# Sincronizador de inversores

Mantém a tabela `geracao_diaria` em dia com o que os inversores informam.

- **Quando roda:** ao acessar qualquer página do painel, se a última execução
  tiver sido há mais de 30 minutos. Roda depois que a página já foi entregue,
  então o acesso não fica mais lento; os números novos aparecem no acesso seguinte.
- **Também roda:** a cada deploy e por `ops/growatt-sync.php?acao=recentes` (com o token de deploy).
- **O que busca:** de cada usina ativa, do último dia gravado até hoje, em janelas
  de 7 dias. Se o sistema ficar dias sem acesso, ele recupera tudo de uma vez.
- **Histórico:** cada execução fica na tabela `sincronizacoes`.
- **Concorrência:** uma trava em `storage/sincronizador.lock` impede duas execuções ao mesmo tempo.
