<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AlunoRiscoIndicador extends Model
{
    protected $table = 'aluno_risco_indicadores';

    public $timestamps = false;

    protected $fillable = [
        'aluno_id',
        'turma_id',
        'etapa',
        'faltas_percentual',
        'atrasos_qtd',
        'atualizado_em',
    ];
}
