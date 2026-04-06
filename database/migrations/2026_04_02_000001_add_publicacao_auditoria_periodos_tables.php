<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('periodos_avaliacao', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('codigo', 50)->unique();
            $table->string('nome', 100);
            $table->unsignedInteger('ordem');
            $table->date('data_abertura');
            $table->date('data_fechamento');
            $table->boolean('ativo')->default(true);
            $table->timestamp('atualizado_em')->useCurrent();
        });

        Schema::create('auditoria_eventos', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('usuario_id')->nullable();
            $table->string('usuario_nome', 200)->nullable();
            $table->string('usuario_perfil', 100)->nullable();
            $table->string('modulo', 100);
            $table->string('acao', 120);
            $table->string('resultado', 50);
            $table->text('detalhes')->nullable();
            $table->string('ip_origem', 80)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('criado_em')->useCurrent();
        });

        Schema::create('configuracao_publicacao_notas', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('modo_publicacao', 20)->default('manual');
            $table->timestamp('atualizado_em')->useCurrent();
        });

        Schema::create('notas_publicadas', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('turma_id');
            $table->unsignedInteger('disciplina_id');
            $table->string('etapa', 30);
            $table->unsignedInteger('publicado_por')->nullable();
            $table->timestamp('publicado_em')->useCurrent();

            $table->unique(['turma_id', 'disciplina_id', 'etapa'], 'uq_notas_publicadas');
            $table->foreign('turma_id')->references('id')->on('turmas')->onDelete('cascade');
            $table->foreign('disciplina_id')->references('id')->on('disciplinas')->onDelete('cascade');
            $table->foreign('publicado_por')->references('id')->on('usuarios')->onDelete('set null');
        });

        DB::table('configuracao_publicacao_notas')->insert([
            'id' => 1,
            'modo_publicacao' => 'manual',
        ]);

        $anoAtual = (int) date('Y');
        DB::table('periodos_avaliacao')->insert([
            [
                'codigo' => '1_bimestre',
                'nome' => '1 bimestre',
                'ordem' => 1,
                'data_abertura' => sprintf('%04d-02-01', $anoAtual),
                'data_fechamento' => sprintf('%04d-04-30', $anoAtual),
                'ativo' => 1,
            ],
            [
                'codigo' => '2_bimestre',
                'nome' => '2 bimestre',
                'ordem' => 2,
                'data_abertura' => sprintf('%04d-05-01', $anoAtual),
                'data_fechamento' => sprintf('%04d-06-30', $anoAtual),
                'ativo' => 1,
            ],
            [
                'codigo' => '3_bimestre',
                'nome' => '3 bimestre',
                'ordem' => 3,
                'data_abertura' => sprintf('%04d-08-01', $anoAtual),
                'data_fechamento' => sprintf('%04d-09-30', $anoAtual),
                'ativo' => 1,
            ],
            [
                'codigo' => '4_bimestre',
                'nome' => '4 bimestre',
                'ordem' => 4,
                'data_abertura' => sprintf('%04d-10-01', $anoAtual),
                'data_fechamento' => sprintf('%04d-12-15', $anoAtual),
                'ativo' => 1,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('notas_publicadas');
        Schema::dropIfExists('configuracao_publicacao_notas');
        Schema::dropIfExists('auditoria_eventos');
        Schema::dropIfExists('periodos_avaliacao');
    }
};