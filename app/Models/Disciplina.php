<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Disciplina extends Model
{
    protected $table = 'disciplinas';

    public $timestamps = false;

    protected $fillable = ['turma_id', 'nome'];

    public function turma()
    {
        return $this->belongsTo(Turma::class, 'turma_id');
    }
}
