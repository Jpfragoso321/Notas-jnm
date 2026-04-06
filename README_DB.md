# README_DB - Banco de Dados e Hospedagem

Este guia responde 2 perguntas:

1. Onde armazenar o banco de dados em producao.
2. Como publicar com custo zero (ou quase zero) no inicio.

## 1) Onde armazenar o banco em producao

Para escola real, nao recomendo deixar banco no mesmo servidor da aplicacao se voce quiser crescer com seguranca.

Use este padrao:

- Aplicacao PHP em um host web.
- Banco MySQL gerenciado em servico separado.
- Backups diarios automaticos + snapshot semanal.
- Usuario de banco com privilegios minimos (sem usar root no app).

Checklist minimo de producao:

- Crie 1 banco por ambiente (`notas_jnm_prod`, `notas_jnm_hml`).
- Ative backup automatico diario.
- Guarde credenciais em variaveis de ambiente (nunca em Git).
- Restrinja acesso do banco por IP/rede quando possivel.
- Ative SSL/TLS entre app e banco.

## 2) Hospedar de graca no comeco (MVP)

Para validar com a escola da sua mae sem custo inicial, o caminho mais simples para PHP + MySQL costuma ser:

- Hospedagem gratis com PHP/MySQL: InfinityFree
- Dominio gratuito: usar subdominio gratis da propria plataforma ou DDNS

### Opcao A (mais simples): subdominio gratis + PHP/MySQL

1. Crie conta na InfinityFree.
2. Crie um host com subdominio gratis.
3. Crie o banco MySQL no painel.
4. No modelo atual (Laravel), publique a pasta `public/` como raiz web.
5. Ajuste o `.env` do Laravel (`.env` na raiz do projeto) com os dados do banco remoto.

Fontes oficiais:

- InfinityFree (PHP/MySQL e subdominio gratis): https://www.infinityfree.com/

### Opcao B: dominio proprio barato + DNS gratis

Dominio "100% gratis" (TLD proprio) hoje e raro e instavel. Para algo serio, use dominio barato e DNS gratis:

1. Compre um dominio de baixo custo.
2. Gerencie DNS no Cloudflare (plano gratis).
3. Aponte para sua hospedagem.

Fonte oficial:

- Cloudflare Registrar (preco de custo, sem markup): https://www.cloudflare.com/products/registrar/

### Opcao C: subdominio gratuito externo

Se quiser custo zero total no inicio:

- No-IP (hostname DDNS gratuito): https://www.noip.com/free/
- EU.org (subdominios gratuitos): https://nic.eu.org/

Observacao: para sistema escolar em producao, prefira dominio proprio para mais confianca e entregabilidade de e-mail.

## 3) Recomendacao pratica (ordem)

1. Validar MVP com InfinityFree + subdominio gratis.
2. Quando a escola comecar a usar diariamente, migrar para hospedagem paga basica + dominio proprio.
3. Em seguida, separar banco gerenciado com backup e monitoramento.

## 4) Pastas e dados sensiveis

No servidor, mantenha:

- Codigo da aplicacao em pasta web.
- Arquivo de configuracao com credenciais fora da pasta publica, quando o host permitir.
- Backups SQL em armazenamento separado (nao apenas no mesmo servidor).

## 5) Nota importante sobre "gratis"

Planos gratuitos costumam ter limites de CPU, memoria, conexoes e disponibilidade. Para uso de alunos/professores diariamente, trate gratuito como etapa de validacao, nao como destino final.
