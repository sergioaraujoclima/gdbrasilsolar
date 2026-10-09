-- PDF original da fatura (nome do arquivo em storage/faturas) e os dados
-- lidos dele, em JSON, para auditoria e reprocessamento.
ALTER TABLE faturas
    ADD COLUMN arquivo_pdf     VARCHAR(120) NULL AFTER observacoes,
    ADD COLUMN dados_extraidos LONGTEXT     NULL AFTER arquivo_pdf;
