<?php

defined('_JEXEC') or die;

require_once JPATH_SITE . '/components/com_generico/services/ImportacaoCsvGiganteService.php';

/*
 * Importação em fatias: cada execução processa até 20 blocos de 500
 * linhas (ou até o tempo acabar) e devolve o job à fila com release().
 * A pausa não gasta tentativa, então o arquivo pode ter qualquer tamanho.
 *
 * Enfileirar:
 *     QueueHelper::push('importar_csv_cooperados', ['importacao_id' => 10], [
 *         'usuario_id' => $userId,
 *         'unico' => ['importacao_id'],     // não duplica se o usuário clicar duas vezes
 *     ]);
 */
class ImportarCsvCooperadosJob extends AbstractJob
{
    public function handle()
    {
        $idImportacao = (int) $this->getPayload('importacao_id', 0);

        if ($idImportacao <= 0) {
            // Dado inválido não melhora tentando de novo.
            return $this->fail('ID da importação não informado no job.', true);
        }

        $baseDirectory = trim((string) $this->getPayload('base_directory', JPATH_SITE . '/components/com_generico/storage'));
        $service = new ImportacaoCsvGiganteService($baseDirectory);

        $resultado = $service->processar($idImportacao, $this->getJob(), [
            'read_size' => 8388608,
            'rows_per_chunk' => 500,
            'max_chunks' => 20,
            // Deixa 20s de folga antes do timeout do job.
            'max_execution_seconds' => max(10, (int) $this->remainingSeconds() - 20),
        ]);

        if (!empty($resultado['completed'])) {
            return $this->complete([
                'importacao_id' => $idImportacao,
                'resultado' => $resultado,
            ]);
        }

        if ($this->lostLock()) {
            // Cancelado pelo usuário: não há o que liberar.
            return false;
        }

        return $this->release();
    }
}
