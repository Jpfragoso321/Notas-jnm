# Portal ECMNM - Projeto Laravel

Projeto organizado no formato **padrao Laravel na raiz**.

## Estrutura atual

- `app/`, `bootstrap/`, `config/`, `routes/`, `resources/`, `storage/`, `tests/`, `vendor/`
- `public/` como webroot do Laravel
- `database/database.sqlite` como banco local
- `legacy_backup/` com codigo antigo (mantido para compatibilidade e referencia)

## Compatibilidade atual

As rotas Laravel (`routes/web.php`) estao carregando os modulos legados de:

- `legacy_backup/public/*.php`

Com isso, o sistema segue funcionando enquanto a migracao para controllers/views Laravel nativos continua.

## Rotas

- `/`
- `/dashboard`
- `/admin`
- `/manager`
- `/turma`
- `/risco`
- `/boletim_pdf`
- `/logout`

## Rodar localmente

```bash
php artisan serve --host=127.0.0.1 --port=8000
```

Abra:

`http://127.0.0.1:8000`

## Banco de dados

- Banco local ativo: `database/database.sqlite`
- Migration do schema escolar: `database/migrations/2026_04_01_000000_create_escola_schema.php`
- Seed base: `database/seeders/DatabaseSeeder.php`

Recriar banco do zero:

```bash
php artisan migrate:fresh --seed
```

## Usuarios iniciais


Professor:

- Usuario: `prof.joao`
- Senha: `123456`

## Testes

```bash
php artisan test
```

## Observacao

O codigo legado foi movido para `legacy_backup/` para manter historico e funcionamento durante a transicao.
