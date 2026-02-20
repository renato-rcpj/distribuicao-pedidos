<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use App\Models\Protocol;
use App\Models\Examiner;
use App\Models\DistributionPointer;

class DistributionService
{
    public function distribute(array $protocolNumbers, string $serviceType, string $date, bool $useAuxiliary = false, array $auxiliaryExaminerIds = [])
    {
        // Usando transaction pra dar rollback se ninguém puder receber o protocolo
        return DB::transaction(function () use ($protocolNumbers, $serviceType, $date, $useAuxiliary, $auxiliaryExaminerIds) {
            
            // 1. Anti-Duplicidade: tira da lista quem já tá no banco
            $existingProtocols = Protocol::whereIn('number', $protocolNumbers)->pluck('number')->toArray();
            $newProtocols = array_diff($protocolNumbers, $existingProtocols);

            if (empty($newProtocols)) {
                return ['success' => false, 'message' => 'Todos os protocolos inseridos já existem.'];
            }

            // 2. Define as equipes e qual ponteiro usar
            $teamIds = [];
            $pointerKey = '';

            if ($useAuxiliary) {
                if (empty($auxiliaryExaminerIds)) {
                    throw new \Exception('Nenhum examinador auxiliar selecionado.');
                }
                $teamIds = array_values($auxiliaryExaminerIds); // Reseta as chaves do array
                $pointerKey = 'pointer_Auxiliar'; 
            } else {
                switch ($serviceType) {
                    case 'Contrato':
                    case 'Alteracao':
                        $teamIds = [1, 2, 3, 4]; // IDs do grupo de contratos
                        $pointerKey = 'pointer_GrupoContratos';
                        break;
                    case 'Ata':
                    case 'Estatuto':
                        $teamIds = [5, 6, 7, 8, 9, 10, 11]; // IDs do grupo de atas
                        $pointerKey = 'pointer_GrupoAtas'; // Ata e Estatuto compartilham a mesma fila
                        break;
                    case 'Documentos':
                    case 'AAEE':
                    case 'CEC':
                        $teamIds = [12, 13]; // IDs da galera de documentos
                        $pointerKey = 'pointer_Documentos';
                        break;
                    default:
                        throw new \Exception('Tipo de serviço inválido.');
                }
            }

            // 3. Puxa os dados só da equipe selecionada
            $examiners = Examiner::whereIn('id', $teamIds)->get()->keyBy('id');
            $teamSize = count($teamIds);

            // 4. Pega onde a fila parou na última vez
            $pointerRecord = DistributionPointer::firstOrCreate(
                ['group_key' => $pointerKey],
                ['last_index' => -1]
            );
            $currentIndex = $pointerRecord->last_index;

            $distributedCount = 0;
            $teamAbsent = false;

            // 5. O loop do Rodízio
            foreach ($newProtocols as $protocolNumber) {
                $foundExaminer = false;
                $attempts = 0;
                
                // Limite de segurança pra não travar o loop: tamanho da equipe + os débitos + uma margem
                $totalDebits = $examiners->sum('debit_balance');
                $maxAttempts = $teamSize + $totalDebits + 2;

                while (!$foundExaminer && $attempts < $maxAttempts) {
                    $currentIndex++;
                    
                    if ($currentIndex >= $teamSize) {
                        $currentIndex = 0; // Fila rodou, volta pro começo
                    }

                    $candidateId = $teamIds[$currentIndex];
                    $candidate = $examiners->get($candidateId);

                    // Só avalia se não tiver de férias/ausente
                    if ($candidate && !$candidate->is_absent) {
                        
                        if ($useAuxiliary) {
                            // Se for equipe auxiliar, o cara ganha um débito pra ser pulado depois na fila normal
                            $candidate->increment('debit_balance');
                            $foundExaminer = clone $candidate; // Clone por causa da referência do objeto
                        } else {
                            // Distribuição normal: verifica se o cara tá devendo compensação
                            if ($candidate->debit_balance > 0) {
                                // Desconta o débito e pula a vez dele
                                $candidate->decrement('debit_balance');
                            } else {
                                // Limpo! Recebe o processo
                                $foundExaminer = clone $candidate;
                            }
                        }
                    }
                    $attempts++;
                }

                // 6. Faz o insert no banco
                if ($foundExaminer) {
                    Protocol::create([
                        'number' => $protocolNumber,
                        'examiner_id' => $foundExaminer->id,
                        'service_type' => $serviceType,
                        'date' => $date,
                    ]);
                    $distributedCount++;
                } else {
                    $teamAbsent = true;
                    break; 
                }
            }

            if ($teamAbsent) {
                DB::rollBack();
                return ['success' => false, 'message' => 'Toda a equipe está ausente ou indisponível devido a débitos.'];
            }

            // 7. Salva a nova posição do ponteiro
            $pointerRecord->update(['last_index' => $currentIndex]);

            return [
                'success' => true, 
                'message' => "$distributedCount protocolos distribuídos com sucesso.",
                'duplicates_ignored' => count($existingProtocols)
            ];
        });
    }
}
