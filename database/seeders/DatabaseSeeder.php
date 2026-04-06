<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('configuracao_avaliacao')->upsert([
            [
                'id' => 1,
                'modo_arredondamento' => 'comercial',
                'casas_decimais' => 2,
                'atualizado_em' => now(),
            ],
        ], ['id']);

        DB::table('configuracao_risco')->upsert([
            [
                'id' => 1,
                'peso_media' => 0.60,
                'peso_faltas' => 0.25,
                'peso_atrasos' => 0.15,
                'limiar_media' => 5.00,
                'limiar_faltas_percentual' => 15.00,
                'limiar_atrasos' => 3,
                'limiar_score_moderado' => 40.00,
                'limiar_score_alto' => 70.00,
                'atualizado_em' => now(),
            ],
        ], ['id']);

        DB::table('usuarios')->upsert([
            [
                'id' => 1,
                'nome' => 'Administrador',
                'usuario' => 'joaopaulofragoso7@gmail.com',
                'senha' => Hash::make('@Caca060405@'),
                'perfil' => 'admin',
                'criado_em' => now(),
            ],
            [
                'id' => 2,
                'nome' => 'Joao Professor',
                'usuario' => 'prof.joao',
                'senha' => Hash::make('123456'),
                'perfil' => 'professor',
                'criado_em' => now(),
            ],
        ], ['id']);

        DB::table('turmas')->upsert([
            ['id' => 1, 'nome' => '6A', 'ano_letivo' => 2026],
            ['id' => 2, 'nome' => '7B', 'ano_letivo' => 2026],
        ], ['id']);

        DB::table('disciplinas')->upsert([
            ['id' => 1, 'turma_id' => 1, 'nome' => 'Matematica'],
            ['id' => 2, 'turma_id' => 1, 'nome' => 'Portugues'],
            ['id' => 3, 'turma_id' => 2, 'nome' => 'Matematica'],
            ['id' => 4, 'turma_id' => 2, 'nome' => 'Ciencias'],
        ], ['id']);

        DB::table('alunos')->upsert([
            ['id' => 1, 'turma_id' => 1, 'nome' => 'Ana Silva'],
            ['id' => 2, 'turma_id' => 1, 'nome' => 'Bruno Costa'],
            ['id' => 3, 'turma_id' => 1, 'nome' => 'Carla Lima'],
            ['id' => 4, 'turma_id' => 2, 'nome' => 'Daniel Rocha'],
            ['id' => 5, 'turma_id' => 2, 'nome' => 'Elaine Souza'],
            ['id' => 6, 'turma_id' => 2, 'nome' => 'Felipe Santos'],
        ], ['id']);

        DB::table('professor_turma')->upsert([
            ['professor_id' => 2, 'turma_id' => 1],
            ['professor_id' => 2, 'turma_id' => 2],
        ], ['professor_id', 'turma_id']);

        DB::table('professor_disciplina')->upsert([
            ['professor_id' => 2, 'disciplina_id' => 1],
            ['professor_id' => 2, 'disciplina_id' => 2],
            ['professor_id' => 2, 'disciplina_id' => 3],
            ['professor_id' => 2, 'disciplina_id' => 4],
        ], ['professor_id', 'disciplina_id']);
    }
}
