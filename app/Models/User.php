<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $table = 'usuarios';

    public $timestamps = false;

    protected $fillable = [
        'nome',
        'usuario',
        'senha',
        'perfil',
        'criado_em',
    ];

    protected $hidden = [
        'senha',
    ];

    public function getAuthPassword(): string
    {
        return (string) $this->senha;
    }

    public function turmas()
    {
        return $this->belongsToMany(Turma::class, 'professor_turma', 'professor_id', 'turma_id');
    }
}
