# distribuicao-pedidos

O nosso algoritmo atual usa a ideia de "Round-Robin" (um rodízio sequencial) e tem algumas regras de negócio bem específicas que precisamos manter: filas unificadas para alguns serviços, pulo de examinadores ausentes e um sistema de compensação de "débitos" para quem recebe protocolo por fora.

Para facilitar, já estruturei como isso deve ficar na arquitetura do Laravel.

1. Estrutura do Banco (Models/Migrations)
Como vamos tirar os arquivos JSON, vamos precisar dessas estruturas no banco:

examiners: id, nome, is_absent (boolean), debit_balance (integer pra cuidar das compensações).

distribution_pointers: id, group_key (string, pra salvar onde a fila parou. Ex: 'pointer_GrupoAtas'), last_index (integer).

protocols: id, number (string), examiner_id (foreign key), service_type (string), date (date).

2. A Classe de Serviço (O coração da regra)
Cria um Service (ex: app/Services/DistributionService.php) para isolar a matemática da coisa.

3. Como usar no Controller:

<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\DistributionService;
use Exception;

class ProtocolController extends Controller
{
    public function store(Request $request, DistributionService $service)
    {
        // limpa a string que vem do textarea
        $protocols = array_filter(explode("\n", str_replace("\r", "", $request->protocolos)));

        $isAuxiliary = $request->boolean('usar_auxiliar_flag');
        $auxTeamIds = $request->input('equipe_auxiliar_ids', []);

        try {
            $result = $service->distribute(
                $protocols,
                $request->tipo_servico,
                $request->data_entrada,
                $isAuxiliary,
                $auxTeamIds
            );

            $status = $result['success'] ? 'success' : 'error';
            
            return back()->with($status, $result['msg']);

        } catch (Exception $e) {
            return back()->with('error', 'Falha ao distribuir: ' . $e->getMessage());
        }
    }
}




