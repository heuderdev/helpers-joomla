<?php

defined('_JEXEC') or die;

require_once JPATH_SITE . '/components/com_generico/jobs/AbstractJob.php';
require_once JPATH_SITE . '/components/com_generico/services/ImportacaoCsvGiganteService.php';

class ImportarCsvCooperadosJob extends AbstractJob
{
    public function handle()
    {
        $idImportacao = (int) $this->getPayload(
            'importacao_id',
            0
        );

        $baseDirectory = trim(
            (string) $this->getPayload(
                'base_directory',
                JPATH_SITE . '/components/com_generico/storage'
            )
        );

        if ($idImportacao <= 0) {
            throw new RuntimeException(
                'ID da importação não informado no job.'
            );
        }

        $service = new ImportacaoCsvGiganteService(
            $baseDirectory
        );

        $resultado = $service->processar(
            $idImportacao,
            $this->getJob(),
            array(
                'read_size' => 8388608,
                'rows_per_chunk' => 500,
                'max_chunks' => 20,
                'max_execution_seconds' => 240
            )
        );

        if (!empty($resultado['completed'])) {
            $this->complete(
                array(
                    'importacao_id' => $idImportacao,
                    'resultado' => $resultado
                )
            );

            return array(
                'status' => 'completed',
                'resultado' => $resultado
            );
        }

        $this->release(0);

        return array(
            'status' => 'released',
            'resultado' => $resultado
        );
    }
}