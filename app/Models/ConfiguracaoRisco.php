<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConfiguracaoRisco extends Model
{
    protected $table = 'configuracao_risco';

    protected $primaryKey = 'id';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'id',
        'peso_media',
        'peso_faltas',
        'peso_atrasos',
        'limiar_media',
        'limiar_faltas_percentual',
        'limiar_atrasos',
        'limiar_score_moderado',
        'limiar_score_alto',
        'atualizado_em',
    ];
}
