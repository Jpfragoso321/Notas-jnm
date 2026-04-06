<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConfiguracaoAvaliacao extends Model
{
    protected $table = 'configuracao_avaliacao';

    protected $primaryKey = 'id';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['id', 'modo_arredondamento', 'casas_decimais', 'atualizado_em'];
}
