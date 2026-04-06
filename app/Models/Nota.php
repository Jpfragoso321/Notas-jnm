<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Nota extends Model
{
    protected $table = 'notas';

    public $timestamps = false;

    protected $fillable = [
        'aluno_id',
        'turma_id',
        'disciplina_id',
        'etapa',
        'componente1',
        'componente1_status',
        'componente2',
        'componente2_status',
        'componente3',
        'componente3_status',
        'componente4',
        'componente4_status',
        'componente5',
        'componente5_status',
        'media_base',
        'peso_diferenciado',
        'justificativa_peso',
        'media_ajustada_peso',
        'recuperacao_nota',
        'recuperacao_aplicada',
        'media',
        'atualizado_em',
    ];
}
