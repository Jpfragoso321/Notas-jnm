<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Turma extends Model
{
    protected $table = 'turmas';

    public $timestamps = false;

    protected $fillable = ['nome', 'ano_letivo'];

    public function alunos()
    {
        return $this->hasMany(Aluno::class, 'turma_id');
    }

    public function disciplinas()
    {
        return $this->hasMany(Disciplina::class, 'turma_id');
    }
}
