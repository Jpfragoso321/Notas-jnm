<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('usuarios', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('nome', 120);
            $table->string('usuario', 120)->unique();
            $table->string('senha', 255);
            $table->string('perfil', 40)->default('professor');
            $table->timestamp('criado_em')->useCurrent();
        });

        Schema::create('configuracao_avaliacao', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('modo_arredondamento', 20)->default('comercial');
            $table->unsignedTinyInteger('casas_decimais')->default(2);
            $table->timestamp('atualizado_em')->useCurrent();
        });

        Schema::create('turmas', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('nome', 100);
            $table->unsignedInteger('ano_letivo');
        });

        Schema::create('professor_turma', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('professor_id');
            $table->unsignedInteger('turma_id');
            $table->unique(['professor_id', 'turma_id'], 'uq_professor_turma');
            $table->foreign('professor_id')->references('id')->on('usuarios')->onDelete('cascade');
            $table->foreign('turma_id')->references('id')->on('turmas')->onDelete('cascade');
        });

        Schema::create('alunos', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('turma_id');
            $table->string('nome', 120);
            $table->foreign('turma_id')->references('id')->on('turmas')->onDelete('cascade');
        });

        Schema::create('disciplinas', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('turma_id');
            $table->string('nome', 120);
            $table->foreign('turma_id')->references('id')->on('turmas')->onDelete('cascade');
        });

        Schema::create('professor_disciplina', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('professor_id');
            $table->unsignedInteger('disciplina_id');
            $table->unique(['professor_id', 'disciplina_id'], 'uq_professor_disciplina');
            $table->foreign('professor_id')->references('id')->on('usuarios')->onDelete('cascade');
            $table->foreign('disciplina_id')->references('id')->on('disciplinas')->onDelete('cascade');
        });

        Schema::create('notas', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('aluno_id');
            $table->unsignedInteger('turma_id');
            $table->unsignedInteger('disciplina_id');
            $table->string('etapa', 30);

            $table->decimal('componente1', 4, 2)->default(0.00);
            $table->string('componente1_status', 20)->default('normal');
            $table->decimal('componente2', 4, 2)->default(0.00);
            $table->string('componente2_status', 20)->default('normal');
            $table->decimal('componente3', 4, 2)->default(0.00);
            $table->string('componente3_status', 20)->default('normal');
            $table->decimal('componente4', 4, 2)->default(0.00);
            $table->string('componente4_status', 20)->default('normal');
            $table->decimal('componente5', 4, 2)->default(0.00);
            $table->string('componente5_status', 20)->default('normal');

            $table->decimal('media_base', 4, 2)->default(0.00);
            $table->decimal('peso_diferenciado', 4, 2)->default(1.00);
            $table->string('justificativa_peso', 255)->nullable();
            $table->decimal('media_ajustada_peso', 4, 2)->default(0.00);
            $table->decimal('recuperacao_nota', 4, 2)->nullable();
            $table->boolean('recuperacao_aplicada')->default(false);
            $table->decimal('media', 4, 2)->default(0.00);
            $table->timestamp('atualizado_em')->useCurrent();

            $table->unique(['aluno_id', 'turma_id', 'disciplina_id', 'etapa'], 'uq_nota');

            $table->foreign('aluno_id')->references('id')->on('alunos')->onDelete('cascade');
            $table->foreign('turma_id')->references('id')->on('turmas')->onDelete('cascade');
            $table->foreign('disciplina_id')->references('id')->on('disciplinas')->onDelete('cascade');
        });

        Schema::create('configuracao_risco', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->decimal('peso_media', 5, 2)->default(0.60);
            $table->decimal('peso_faltas', 5, 2)->default(0.25);
            $table->decimal('peso_atrasos', 5, 2)->default(0.15);
            $table->decimal('limiar_media', 4, 2)->default(5.00);
            $table->decimal('limiar_faltas_percentual', 5, 2)->default(15.00);
            $table->unsignedInteger('limiar_atrasos')->default(3);
            $table->decimal('limiar_score_moderado', 5, 2)->default(40.00);
            $table->decimal('limiar_score_alto', 5, 2)->default(70.00);
            $table->timestamp('atualizado_em')->useCurrent();
        });

        Schema::create('aluno_risco_indicadores', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('aluno_id');
            $table->unsignedInteger('turma_id');
            $table->string('etapa', 30);
            $table->decimal('faltas_percentual', 5, 2)->default(0.00);
            $table->unsignedInteger('atrasos_qtd')->default(0);
            $table->timestamp('atualizado_em')->useCurrent();

            $table->unique(['aluno_id', 'turma_id', 'etapa'], 'uq_aluno_risco');
            $table->foreign('aluno_id')->references('id')->on('alunos')->onDelete('cascade');
            $table->foreign('turma_id')->references('id')->on('turmas')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aluno_risco_indicadores');
        Schema::dropIfExists('configuracao_risco');
        Schema::dropIfExists('notas');
        Schema::dropIfExists('professor_disciplina');
        Schema::dropIfExists('disciplinas');
        Schema::dropIfExists('alunos');
        Schema::dropIfExists('professor_turma');
        Schema::dropIfExists('turmas');
        Schema::dropIfExists('configuracao_avaliacao');
        Schema::dropIfExists('usuarios');
    }
};
