<?php

namespace App\Services;

use App\Models\Protocol;
use App\Models\Examiner;
use App\Models\DistributionPointer;
use Illuminate\Support\Facades\DB;
use Exception;

class DistributionService
{
    public function distribute(array $protocols, string $serviceType, string $date, bool $isAuxiliary = false, array $auxTeamIds = [])
    {
        return DB::transaction(function () use ($protocols, $serviceType, $date, $isAuxiliary, $auxTeamIds) {
            
            // filtra quem já existe no banco pra evitar duplicidade
            $existing = Protocol::whereIn('number', $protocols)->pluck('number')->toArray();
            $newProtocols = array_diff($protocols, $existing);

            if (empty($newProtocols)) {
                return ['success' => false, 'msg' => 'Todos os protocolos informados já constam na base.'];
            }

            // carrega as configs da equipe com base no serviço
            $teamData = $this->getTeamSettings($serviceType, $isAuxiliary, $auxTeamIds);
            $teamIds = $teamData['ids'];
            $pointerKey = $teamData['pointer'];

            if (empty($teamIds)) {
                throw new Exception('Nenhum examinador definido para esta distribuição.');
            }

            $examiners = Examiner::whereIn('id', $teamIds)->get()->keyBy('id');
            $teamSize = count($teamIds);

            // pega o index de onde a fila parou (ou começa do -1 se for a primeira vez)
            $pointer = DistributionPointer::firstOrCreate(
                ['group_key' => $pointerKey],
                ['last_index' => -1]
            );
            
            $currentIndex = $pointer->last_index;
            $distributedCount = 0;

            foreach ($newProtocols as $protocolNumber) {
                $assignedExaminer = null;
                $attempts = 0;
                
                // limite de segurança: rodar a equipe inteira + margem dos débitos pra não travar num loop
                $maxAttempts = $teamSize + $examiners->sum('debit_balance') + 2;

                while (!$assignedExaminer && $attempts < $maxAttempts) {
                    $currentIndex++;
                    
                    if ($currentIndex >= $teamSize) {
                        $currentIndex = 0; // reseta a fila
                    }

                    $candidate = $examiners->get($teamIds[$currentIndex]);

                    // avalia só se o cara estiver ativo
                    if ($candidate && !$candidate->is_absent) {
                        
                        if ($isAuxiliary) {
                            // pega por fora, ganha 1 débito pra compensar na fila normal
                            $candidate->increment('debit_balance');
                            $assignedExaminer = clone $candidate;
                        } else {
                            if ($candidate->debit_balance > 0) {
                                // tá devendo compensação: paga 1 débito e pula a vez
                                $candidate->decrement('debit_balance');
                            } else {
                                $assignedExaminer = clone $candidate;
                            }
                        }
                    }
                    $attempts++;
                }

                if (!$assignedExaminer) {
                    DB::rollBack();
                    return ['success' => false, 'msg' => 'Equipe indisponível ou travada por excesso de ausências/débitos.'];
                }

                Protocol::create([
                    'number' => $protocolNumber,
                    'examiner_id' => $assignedExaminer->id,
                    'service_type' => $serviceType,
                    'date' => $date,
                ]);
                
                $distributedCount++;
            }

            // salva de onde vai começar na próxima rodada
            $pointer->update(['last_index' => $currentIndex]);

            return [
                'success' => true, 
                'msg' => "{$distributedCount} protocolos distribuídos com sucesso.",
                'ignored' => count($existing)
            ];
        });
    }

    /**
     * Mapeia os IDs e ponteiros de cada grupo
     */
    private function getTeamSettings(string $type, bool $isAuxiliary, array $auxIds): array
    {
        if ($isAuxiliary) {
            return [
                'ids' => array_values($auxIds),
                'pointer' => 'pointer_Auxiliar'
            ];
        }

        // TODO: Num cenário ideal, isso vem do banco. Fixado conforme regra de negócio.
        return match ($type) {
            'Contrato', 'Alteracao' => [
                'ids' => [1, 2, 3, 4], 
                'pointer' => 'pointer_GrupoContratos'
            ],
            'Ata', 'Estatuto' => [
                'ids' => [5, 6, 7, 8, 9, 10, 11], 
                'pointer' => 'pointer_GrupoAtas'
            ],
            'Documentos', 'AAEE', 'CEC' => [
                'ids' => [12, 13], 
                'pointer' => 'pointer_Documentos'
            ],
            default => throw new Exception("Serviço não reconhecido: {$type}"),
        };
    }
}
