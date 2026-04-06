# Relatorio de Teste de Acesso por Perfil

Data: 2026-04-02
Ambiente: local (`http://localhost:8000`)
Metodo: probe automatizado simulando sessao autenticada por perfil.

## Rotas testadas
- `/dashboard`
- `/admin`
- `/manager?turma_id=1`
- `/turma?turma_id=1`
- `/risco?turma_id=1&etapa=1_bimestre`
- `/frequencia?turma_id=1`
- `/historico`
- `/conselho`
- `/notificacoes`
- `/auditoria`
- `/relatorios`
- `/backup`
- `/rubricas?turma_id=1&disciplina_id=1`
- `/analytics-avancado?turma_id=1&etapa=1_bimestre`
- `/portal-aluno`

## Resultado resumido

### Admin
- Acesso permitido em todas as rotas testadas.

### Coordenacao pedagogica
- Bloqueado corretamente em:
  - `/admin`
  - `/backup`
- Permitido no restante das rotas de gestao.

### Diretora (simulada)
- Bloqueado corretamente em:
  - `/admin`
  - `/backup`
- Permitido no restante das rotas de gestao.

### Secretario (simulado)
- Bloqueado corretamente em:
  - `/admin`
  - `/backup`
- Permitido no restante das rotas de gestao.

### Professor
- Bloqueado corretamente em:
  - `/admin`
  - `/manager`
  - `/conselho`
  - `/auditoria`
  - `/relatorios`
  - `/backup`
  - `/rubricas`
  - `/analytics-avancado`
- Permitido em:
  - `/dashboard`
  - `/risco`
  - `/frequencia`
  - `/historico`
  - `/notificacoes`
  - `/portal-aluno`
- Observacao importante:
  - `/turma?turma_id=1` retornou bloqueio para professor (`Acesso negado: voce nao possui materias vinculadas nesta turma.`), indicando que o professor testado nao tem vinculo de disciplina para essa turma no banco atual.

## Achados
1. Matriz de permissao principal esta coerente para gestao x admin.
2. Professor esta corretamente sem acesso a modulos de gestao.
3. Existe dependencia de vinculo professor-disciplina para liberar `turma.php`.

## Proximas validacoes manuais recomendadas
1. Login real com cada perfil e fluxo completo de tela (CRUD e exportacao).
2. Professor com vinculo valido em `professor_disciplina` para confirmar acesso total ao diario da turma.
3. Verificar se `/historico` e `/frequencia` devem mesmo ficar liberados para professor (regra pedagogica da escola).