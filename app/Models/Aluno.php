<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Aluno extends Model
{
    protected $table = 'alunos';

    public $timestamps = false;

    protected $fillable = ['turma_id', 'nome'];

    public function turma()
    {
        return $this->belongsTo(Turma::class, 'turma_id');
    }
}
