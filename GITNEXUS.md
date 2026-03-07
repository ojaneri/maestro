# GitNexus - Code Intelligence & Knowledge Graph

> **Framework de instalação e integração para qualquer projeto**

## 📖 O Que É GitNexus

GitNexus é um sistema de **inteligência de código baseado em grafos de conhecimento** que indexa projetos para criar um mapa completo de símbolos, dependências, fluxos de execução e clusters funcionais.

### Como Funciona

1. **Indexação:** GitNexus analisa todo o código-fonte e cria um grafo de conhecimento armazenado localmente em `.gitnexus/`
2. **MCP (Model Context Protocol):** Disponibiliza ferramentas especializadas que assistentes de IA podem usar
3. **Análise de Impacto:** Permite consultar "o que quebra se eu mudar X?" antes de modificar código
4. **Skills Especializadas:** Workflows pré-configurados para debugging, refactoring, exploração de código

### Por Que Usar GitNexus

- ✅ **Previne breaking changes** - Análise de blast radius antes de modificações
- ✅ **Acelera debugging** - Trace call chains e execution flows
- ✅ **Refactoring seguro** - Renomeações coordenadas em múltiplos arquivos
- ✅ **Exploração de código** - Entenda arquiteturas desconhecidas rapidamente
- ✅ **Pre-commit validation** - Detecta impactos de mudanças antes do commit

---

## 🚀 Instalação

### Passo 1: Instalar GitNexus via npm

```bash
# Instalação global (recomendado)
npm install -g @gitnexus/cli

# OU instalação local no projeto
npm install --save-dev @gitnexus/cli
```

**Verificar instalação:**
```bash
gitnexus --version
```

### Passo 2: Inicializar no Projeto

```bash
# Na raiz do projeto
cd /caminho/do/projeto

# Inicializar GitNexus
npx gitnexus init

# Isso cria:
# - .gitnexus/ (diretório de índice, adicionar ao .gitignore)
# - .gitnexusrc (configuração opcional)
```

### Passo 3: Indexar o Projeto

```bash
# Indexação completa (primeira vez)
npx gitnexus analyze

# Verificar status da indexação
npx gitnexus status

# Saída esperada:
# ✓ Indexed: 12722 symbols, 21116 relationships, 300 execution flows
# ✓ Last updated: 2 minutes ago
# ✓ Status: Fresh
```

**Adicionar ao `.gitignore`:**
```gitignore
# GitNexus
.gitnexus/
```

---

## 📁 Integração com Memory Bank

### Criar `memory-bank/gitnexus.md`

```bash
# Criar diretório se não existir
mkdir -p memory-bank

# Criar arquivo
touch memory-bank/gitnexus.md
```

**Template completo do arquivo:**

```markdown
# GitNexus - Knowledge Graph Integration

## Overview
GitNexus indexes this codebase into a knowledge graph with symbols, dependencies, execution flows, and clusters. Use it to prevent breaking changes and understand architectural impact.

## Index Status
**Location:** `.gitnexus/` (gitignored)
**Check freshness:** `gitnexus status`
**Re-index:** `gitnexus analyze`

## Critical Use Cases

### 1. Before Modifying Core Functions
**Example:** Changing `loadConfig()` in `src/core/config.js`
```bash
# Check what depends on this function
gitnexus context --name loadConfig
gitnexus impact --target loadConfig --direction upstream
```

### 2. Before Renaming Variables/Functions
**Example:** Renaming `userId` to `userIdentifier`
```bash
# Use GitNexus rename tool (safer than manual find/replace)
gitnexus rename --symbol_name userId --new_name userIdentifier --dry_run
```

### 3. Pre-Commit Validation
```bash
# Before committing changes, check impact
gitnexus detect_changes --scope all
```

### 4. Debugging Call Chains
**Example:** Tracing how requests are processed
```bash
# Find execution flow from entry point
gitnexus context --name processRequest
# Shows all callers and callees with process participation
```

## MCP Tools Reference

### context()
**Purpose:** 360-degree view of a symbol (function, class, etc.)
**Returns:** Incoming/outgoing calls, imports, process participation
**Example:** `context({name: "validateUser"})`

### impact()
**Purpose:** Blast radius analysis - what will break if you change this?
**Parameters:**
- `target`: Symbol name
- `direction`: "upstream" (dependents) or "downstream" (dependencies)
- `minConfidence`: 0.0-1.0 (filter weak relationships)
**Example:** `impact({target: "UserService", direction: "upstream", minConfidence: 0.8})`

### detect_changes()
**Purpose:** Git-diff based impact analysis (pre-commit check)
**Returns:** Changed symbols, affected processes, risk level
**Example:** `detect_changes({scope: "all"})`

### rename()
**Purpose:** Multi-file coordinated rename with graph + text search
**Parameters:**
- `symbol_name`: Current name
- `new_name`: New name
- `dry_run`: true/false
**Example:** `rename({symbol_name: "validateUser", new_name: "verifyUser", dry_run: true})`

### query()
**Purpose:** Hybrid search (BM25 + semantic + RRF)
**Returns:** Process-grouped results with context
**Example:** `query({query: "authentication middleware"})`

## Workflow Integration

### Standard Development Workflow
1. **Start task** → Read `gitnexus://repo/{name}/context` (check index freshness)
2. **Before modifying code** → Run `impact()` on target symbols
3. **During refactoring** → Use `.claude/skills/gitnexus/gitnexus-refactoring/SKILL.md`
4. **Before commit** → Run `detect_changes()`
5. **If index stale** → Run `npx gitnexus analyze`

### Critical Files to Always Check Impact:
- Core configuration files
- Database layer files
- Authentication/authorization modules
- API route handlers
- Message/event processors

## Skills Installed

| Skill | Path | When to Use |
|-------|------|-------------|
| Exploring | `.claude/skills/gitnexus/gitnexus-exploring/` | Understanding unfamiliar code |
| Impact Analysis | `.claude/skills/gitnexus/gitnexus-impact-analysis/` | Before modifications |
| Debugging | `.claude/skills/gitnexus/gitnexus-debugging/` | Tracing bugs |
| Refactoring | `.claude/skills/gitnexus/gitnexus-refactoring/` | Planning safe refactors |
| CLI | `.claude/skills/gitnexus/gitnexus-cli/` | Index management |
| Guide | `.claude/skills/gitnexus/gitnexus-guide/` | Tool reference |

## Maintenance

### Keep Index Fresh
- **Auto-check:** GitNexus warns if index is stale via `context` resource
- **Manual check:** `gitnexus status`
- **Re-index:** `gitnexus analyze` (run after major refactors)

### Multi-Repo Setup
- GitNexus supports multiple indexed repos via global registry
- Registry location: `~/.gitnexus/registry.json`
- Each repo stores index in `.gitnexus/` (portable, gitignored)

## Resources

- **Repo Context:** `gitnexus://repo/{name}/context` (stats, staleness, tools)
- **Clusters:** `gitnexus://repo/{name}/clusters` (functional groupings)
- **Processes:** `gitnexus://repo/{name}/processes` (execution flows)
- **Schema:** `gitnexus://repo/{name}/schema` (for Cypher queries)

## Best Practices

1. **Always check index freshness first** - Read context resource
2. **Use impact() before critical changes** - Especially in core systems
3. **Prefer rename() tool over manual find/replace** - Safer, graph-aware
4. **Run detect_changes() before commits** - Catch breaking changes early
5. **Consult skills when stuck** - They have detailed workflows for specific tasks
```

### Atualizar `memory-bank/systemPatterns.md`

Adicionar a seguinte seção ao final do arquivo:

```markdown
## GitNexus Knowledge Graph

**Index Location:** `.gitnexus/` (gitignored)
**Status Check:** `gitnexus status`
**Re-index:** `npx gitnexus analyze`

### When to Use GitNexus:
1. **Before modifying critical code** - Run `impact()` analysis
2. **Before renaming symbols** - Use `rename()` tool for coordinated changes
3. **Debugging complex issues** - Trace call chains with `context()`
4. **Pre-commit validation** - Run `detect_changes()` to check for breaking changes

### Integration Points:
- **Read AGENTS.md** on task start to check index freshness
- **Consult `.claude/skills/gitnexus/*/SKILL.md`** for specialized workflows
- **Use MCP tools** via AI assistant for graph queries
```

---

## ⚙️ Integração com .clinerules

Adicionar a seguinte seção ao arquivo `.clinerules`:

```bash
## GitNexus Integration (KNOWLEDGE GRAPH - PREVENT REGRESSIONS)

**ALWAYS** consult GitNexus antes de modificações que possam causar breaking changes:

### When to Use GitNexus:
1. **Before Refactoring:** Check blast radius with impact analysis
2. **Before Renaming:** Verify all references and dependencies
3. **Debugging Issues:** Trace execution flows through call chains
4. **Architecture Questions:** Explore clusters and relationships

### Quick Start Commands:
```bash
# Check if index is fresh (run this FIRST)
gitnexus status

# Re-index if stale
gitnexus analyze

# Impact analysis before changing code
# Example: Before modifying loadConfig in src/core/config.js
# Use GitNexus impact tool to see what depends on it
```

### GitNexus Skills (Auto-installed):
- `.claude/skills/gitnexus/gitnexus-exploring/` - Navigate unfamiliar code
- `.claude/skills/gitnexus/gitnexus-impact-analysis/` - Blast radius analysis
- `.claude/skills/gitnexus/gitnexus-debugging/` - Trace bugs through call chains
- `.claude/skills/gitnexus/gitnexus-refactoring/` - Plan safe refactors

### MCP Tools Available:
1. `context({name: "function_name"})` - 360° symbol view
2. `impact({target: "ClassName", direction: "upstream"})` - What depends on this?
3. `detect_changes({scope: "all"})` - Pre-commit impact analysis
4. `rename({symbol_name: "old", new_name: "new"})` - Multi-file rename
5. `query({query: "search term"})` - Hybrid search (BM25 + semantic)

### Integration Rules:
- **Pre-Modification:** Always run `impact()` on critical functions
- **Pre-Commit:** Run `detect_changes()` to verify no breaking changes
- **Cross-File Changes:** Use `rename()` tool instead of manual find/replace
- **Debugging:** Start with `context()` to understand symbol relationships

### Read These Files:
- `AGENTS.md` - GitNexus overview and workflow
- `CLAUDE.md` - Same as AGENTS.md (alternative name)
- `.claude/skills/gitnexus/gitnexus-guide/SKILL.md` - Complete tool reference
```

---

## 📋 Criar AGENTS.md e CLAUDE.md

Ambos os arquivos devem ter o mesmo conteúdo (são aliases):

```bash
# Criar ambos os arquivos
touch AGENTS.md CLAUDE.md
```

**Template completo (usar para ambos):**

```markdown
<!-- gitnexus:start -->
# GitNexus MCP

This project is indexed by GitNexus as **{nome-do-projeto}** ({X} symbols, {Y} relationships, {Z} execution flows).

## Always Start Here

1. **Read `gitnexus://repo/{name}/context`** — codebase overview + check index freshness
2. **Match your task to a skill below** and **read that skill file**
3. **Follow the skill's workflow and checklist**

> If step 1 warns the index is stale, run `npx gitnexus analyze` in the terminal first.

## Skills

| Task | Read this skill file |
|------|---------------------|
| Understand architecture / "How does X work?" | `.claude/skills/gitnexus/gitnexus-exploring/SKILL.md` |
| Blast radius / "What breaks if I change X?" | `.claude/skills/gitnexus/gitnexus-impact-analysis/SKILL.md` |
| Trace bugs / "Why is X failing?" | `.claude/skills/gitnexus/gitnexus-debugging/SKILL.md` |
| Rename / extract / split / refactor | `.claude/skills/gitnexus/gitnexus-refactoring/SKILL.md` |
| Tools, resources, schema reference | `.claude/skills/gitnexus/gitnexus-guide/SKILL.md` |
| Index, status, clean, wiki CLI commands | `.claude/skills/gitnexus/gitnexus-cli/SKILL.md` |

<!-- gitnexus:end -->
```

**Nota:** Substitua `{nome-do-projeto}`, `{X}`, `{Y}`, `{Z}` com os valores reais após rodar `gitnexus analyze`.

---

## 🎯 Como Usar GitNexus

### Antes de Modificar Código (Impact Analysis)

**Cenário:** Você vai modificar uma função crítica.

```bash
# 1. Verificar status do índice
gitnexus status

# 2. Consultar contexto da função
gitnexus context --name nomeDaFuncao

# 3. Análise de impacto (upstream = o que depende disso)
gitnexus impact --target nomeDaFuncao --direction upstream

# 4. Análise de impacto (downstream = de que isso depende)
gitnexus impact --target nomeDaFuncao --direction downstream
```

**Via MCP (no AI assistant):**
```
"Before modifying loadConfig(), show me what depends on it"
→ Assistant usa: impact({target: "loadConfig", direction: "upstream"})
```

### Antes de Refatorar (Blast Radius)

**Cenário:** Planejando um grande refactoring.

```bash
# 1. Ler a skill de refactoring
cat .claude/skills/gitnexus/gitnexus-refactoring/SKILL.md

# 2. Identificar símbolos afetados
gitnexus query --query "authentication middleware"

# 3. Para cada símbolo, verificar impacto
gitnexus impact --target AuthMiddleware --direction upstream --min-confidence 0.8

# 4. Dry-run de renomeações
gitnexus rename --symbol_name oldName --new_name newName --dry_run
```

**Via MCP (no AI assistant):**
```
"I'm refactoring the auth system. Show me the blast radius of AuthMiddleware"
→ Assistant lê skill de refactoring e executa workflow completo
```

### Debugging (Call Chains)

**Cenário:** Bug em produção, precisa rastrear o fluxo.

```bash
# 1. Ler a skill de debugging
cat .claude/skills/gitnexus/gitnexus-debugging/SKILL.md

# 2. Encontrar o ponto de entrada do bug
gitnexus query --query "error handling login"

# 3. Traçar call chain
gitnexus context --name handleLoginError

# 4. Investigar cada caller
gitnexus context --name authenticateUser
```

**Via MCP (no AI assistant):**
```
"Trace the call chain for handleLoginError"
→ Assistant usa: context({name: "handleLoginError"})
→ Mostra todos os callers e callees com contexto
```

### Explorar Arquitetura

**Cenário:** Novo no projeto, precisa entender a estrutura.

```bash
# 1. Ler a skill de exploração
cat .claude/skills/gitnexus/gitnexus-exploring/SKILL.md

# 2. Ver clusters funcionais
gitnexus clusters

# 3. Ver processos principais
gitnexus processes

# 4. Busca semântica
gitnexus query --query "how does authentication work"
```

**Via MCP (no AI assistant):**
```
"Explain the architecture of the authentication system"
→ Assistant lê gitnexus://repo/{name}/context
→ Usa query() para encontrar auth-related symbols
→ Usa context() em cada símbolo para mapear relações
```

---

## 💡 Exemplos Práticos

### Exemplo 1: Renomear Variável Global

**Problema:** Preciso renomear `userId` para `userIdentifier` em todo o projeto.

```bash
# 1. Dry-run para ver o que será modificado
npx gitnexus rename --symbol_name userId --new_name userIdentifier --dry_run

# Saída:
# ✓ Found 47 occurrences across 12 files:
#   - src/auth/login.js (8 occurrences)
#   - src/models/User.js (15 occurrences)
#   - ...

# 2. Executar renomeação
npx gitnexus rename --symbol_name userId --new_name userIdentifier

# 3. Verificar impacto pós-renomeação
npx gitnexus detect_changes --scope all
```

### Exemplo 2: Pre-Commit Validation

**Problema:** Garanti que minhas mudanças não quebram nada antes de commitar.

```bash
# Antes de git commit
npx gitnexus detect_changes --scope all

# Saída:
# ⚠ Changed symbols: 3
#   - modifiedFunction (src/core/api.js)
#   - helperMethod (src/utils/helpers.js)
#   - API_ENDPOINT (src/config.js)
#
# 📊 Affected processes: 2
#   - User Authentication Flow (HIGH RISK)
#   - Data Validation Pipeline (LOW RISK)
#
# ✓ Safe to commit (no critical breaking changes detected)
```

### Exemplo 3: Debugging Call Chain

**Problema:** Erro em produção: "Cannot read property 'data' of undefined" em `processResponse()`.

```bash
# 1. Encontrar o símbolo
npx gitnexus context --name processResponse

# Saída:
# Symbol: processResponse
# File: src/api/client.js:45
# 
# Callers (upstream):
#   - handleAPIResponse (src/api/handlers.js:78)
#   - fetchUserData (src/services/user.js:123)
#
# Callees (downstream):
#   - validateData (src/utils/validator.js:34)
#   - transformPayload (src/utils/transform.js:67)

# 2. Investigar cada caller
npx gitnexus context --name handleAPIResponse
npx gitnexus context --name fetchUserData

# 3. Identificar onde 'data' pode ser undefined
# Resultado: fetchUserData não valida resposta antes de passar para processResponse
```

### Exemplo 4: Impact Analysis Antes de Mudança Crítica

**Problema:** Preciso modificar `loadConfig()` que carrega configurações da aplicação.

```bash
# 1. Verificar o que depende de loadConfig
npx gitnexus impact --target loadConfig --direction upstream --min-confidence 0.7

# Saída:
# ⚠ Blast Radius: 23 dependent symbols
# 
# HIGH IMPACT (confidence > 0.9):
#   - initializeApp (src/index.js) - CRITICAL
#   - setupDatabase (src/db/init.js) - CRITICAL
#   - configureAuth (src/auth/setup.js) - HIGH
#
# MEDIUM IMPACT (confidence 0.7-0.9):
#   - loadPlugins (src/plugins/loader.js) - MEDIUM
#   - setupLogging (src/utils/logger.js) - MEDIUM
#
# Recommendation: Test these 5 critical paths after modification

# 2. Ver dependências (downstream)
npx gitnexus impact --target loadConfig --direction downstream

# Saída:
# Dependencies:
#   - readFile (fs) - EXTERNAL
#   - parseYAML (src/utils/yaml.js) - INTERNAL
#   - validateSchema (src/config/validator.js) - INTERNAL
```

---

## 🔧 Manutenção

### Re-indexar Após Mudanças

```bash
# Após pull/merge/grande refactoring
npx gitnexus analyze

# Análise incremental (mais rápido, experimental)
npx gitnexus analyze --incremental

# Forçar re-indexação completa
npx gitnexus analyze --force
```

### Verificar Status

```bash
# Status básico
npx gitnexus status

# Saída:
# Repository: my-project
# Index: Fresh (updated 5 minutes ago)
# Symbols: 12722
# Relationships: 21116
# Execution flows: 300

# Status detalhado
npx gitnexus status --verbose
```

### Limpar Cache

```bash
# Limpar cache de análises antigas
npx gitnexus clean

# Remover índice completamente (requer re-análise)
npx gitnexus clean --all

# Remover e re-indexar
npx gitnexus clean --all && npx gitnexus analyze
```

### Multi-Repo Setup

```bash
# Ver todos os repos indexados
npx gitnexus list

# Saída:
# Indexed repositories:
#   - my-project (/home/user/projects/my-project)
#   - api-server (/home/user/projects/api-server)

# Remover repo do registry
npx gitnexus unregister my-project

# Re-registrar
cd /home/user/projects/my-project
npx gitnexus init
```

---

## 📚 Skills Disponíveis

GitNexus instala automaticamente skills especializadas em `.claude/skills/gitnexus/`:

### 1. **gitnexus-exploring** - Exploração de Código
**Use quando:** Precisar entender arquitetura desconhecida
**Workflow:**
1. Lê `gitnexus://repo/{name}/context` para overview
2. Usa `query()` para busca semântica
3. Usa `context()` em símbolos-chave
4. Mapeia clusters e processos

**Arquivo:** `.claude/skills/gitnexus/gitnexus-exploring/SKILL.md`

### 2. **gitnexus-impact-analysis** - Análise de Blast Radius
**Use quando:** Antes de modificar código crítico
**Workflow:**
1. Identifica símbolos afetados
2. Roda `impact()` upstream (dependentes)
3. Roda `impact()` downstream (dependências)
4. Avalia risco e planeja testes

**Arquivo:** `.claude/skills/gitnexus/gitnexus-impact-analysis/SKILL.md`

### 3. **gitnexus-debugging** - Rastreamento de Bugs
**Use quando:** Debugging de issues complexos
**Workflow:**
1. Usa `query()` para encontrar símbolos relacionados ao bug
2. Usa `context()` para traçar call chains
3. Analisa execution flows
4. Identifica root cause

**Arquivo:** `.claude/skills/gitnexus/gitnexus-debugging/SKILL.md`

### 4. **gitnexus-refactoring** - Refactoring Seguro
**Use quando:** Planejando refactorings grandes
**Workflow:**
1. Mapeia blast radius com `impact()`
2. Planeja renomeações com `rename()` dry-run
3. Valida mudanças com `detect_changes()`
4. Executa refactoring coordenado

**Arquivo:** `.claude/skills/gitnexus/gitnexus-refactoring/SKILL.md`

### 5. **gitnexus-cli** - Comandos CLI
**Use quando:** Precisar gerenciar índice manualmente
**Comandos:**
- `gitnexus init` - Inicializar projeto
- `gitnexus analyze` - Indexar/re-indexar
- `gitnexus status` - Ver status
- `gitnexus clean` - Limpar cache

**Arquivo:** `.claude/skills/gitnexus/gitnexus-cli/SKILL.md`

### 6. **gitnexus-guide** - Referência Completa
**Use quando:** Precisar de referência de tools e recursos MCP
**Conteúdo:**
- Schema completo do MCP
- Todos os tools disponíveis
- Exemplos de queries Cypher
- Resources URIs

**Arquivo:** `.claude/skills/gitnexus/gitnexus-guide/SKILL.md`

---

## ⚠️ Troubleshooting

### Problema: "Index is stale"

**Sintoma:** GitNexus avisa que o índice está desatualizado

**Solução:**
```bash
npx gitnexus analyze
```

**Prevenção:** Configure git hooks para re-indexar automaticamente:
```bash
# .git/hooks/post-merge
#!/bin/bash
npx gitnexus analyze --incremental
```

### Problema: "Symbol not found"

**Sintoma:** `context()` ou `impact()` não encontra um símbolo

**Causas possíveis:**
1. Índice desatualizado → Rodar `gitnexus analyze`
2. Nome incorreto → Verificar nome exato no código
3. Símbolo não-público → GitNexus só indexa exports/public symbols

**Solução:**
```bash
# Re-indexar
npx gitnexus analyze

# Buscar símbolo similar
npx gitnexus query --query "nome parcial do símbolo"
```

### Problema: Análise muito lenta

**Sintoma:** `gitnexus analyze` demora muito

**Soluções:**
```bash
# 1. Use análise incremental (mais rápido)
npx gitnexus analyze --incremental

# 2. Exclua diretórios desnecessários
# Criar .gitnexusignore
echo "node_modules/" >> .gitnexusignore
echo "dist/" >> .gitnexusignore
echo "build/" >> .gitnexusignore

# 3. Limpar e re-indexar
npx gitnexus clean --all
npx gitnexus analyze
```

### Problema: MCP tools não disponíveis no AI assistant

**Sintoma:** Assistant não consegue usar `context()`, `impact()`, etc.

**Solução:**
1. Verificar se GitNexus está instalado:
```bash
gitnexus --version
```

2. Verificar se MCP está configurado no AI assistant
3. Re-inicializar sessão do AI assistant
4. Confirmar que `AGENTS.md` existe e está correto

### Problema: Resultados imprecisos

**Sintoma:** `impact()` mostra símbolos não relacionados

**Solução:**
```bash
# Use parâmetro minConfidence para filtrar
npx gitnexus impact --target myFunction --direction upstream --min-confidence 0.8

# Via MCP:
impact({target: "myFunction", direction: "upstream", minConfidence: 0.8})
```

### Problema: Espaço em disco

**Sintoma:** `.gitnexus/` está ocupando muito espaço

**Solução:**
```bash
# Ver tamanho do índice
du -sh .gitnexus/

# Limpar cache antigo
npx gitnexus clean

# Remover e re-indexar (índice menor e otimizado)
npx gitnexus clean --all
npx gitnexus analyze
```

---

## 📋 Checklist de Instalação

Use este checklist ao integrar GitNexus em um novo projeto:

- [ ] Instalar GitNexus: `npm install -g @gitnexus/cli`
- [ ] Inicializar projeto: `npx gitnexus init`
- [ ] Adicionar `.gitnexus/` ao `.gitignore`
- [ ] Indexar projeto: `npx gitnexus analyze`
- [ ] Verificar status: `npx gitnexus status`
- [ ] Criar `memory-bank/gitnexus.md` (usar template acima)
- [ ] Atualizar `memory-bank/systemPatterns.md` (adicionar seção GitNexus)
- [ ] Adicionar seção GitNexus em `.clinerules`
- [ ] Criar `AGENTS.md` e `CLAUDE.md` (mesmo conteúdo)
- [ ] Atualizar `{name}` e stats em `AGENTS.md`/`CLAUDE.md`
- [ ] Verificar skills instaladas: `ls .claude/skills/gitnexus/`
- [ ] Testar MCP tools no AI assistant
- [ ] Configurar git hooks (opcional)
- [ ] Documentar critical files no `memory-bank/gitnexus.md`

---

## 🔗 Recursos Adicionais

### MCP Resources URIs

Estas URIs podem ser lidas pelo AI assistant via MCP:

- `gitnexus://repo/{name}/context` - Overview do projeto + check de freshness
- `gitnexus://repo/{name}/clusters` - Agrupamentos funcionais de código
- `gitnexus://repo/{name}/processes` - Fluxos de execução principais
- `gitnexus://repo/{name}/schema` - Schema do grafo para queries Cypher

### Comandos Quick Reference

```bash
# Instalação e setup
npm install -g @gitnexus/cli
npx gitnexus init
npx gitnexus analyze

# Status e manutenção
npx gitnexus status
npx gitnexus clean
npx gitnexus list

# Análise
npx gitnexus context --name symbolName
npx gitnexus impact --target symbolName --direction upstream
npx gitnexus query --query "search term"
npx gitnexus rename --symbol_name old --new_name new --dry_run

# Pre-commit
npx gitnexus detect_changes --scope all
```

### Estrutura de Arquivos GitNexus

```
projeto/
├── .gitnexus/              # Índice do knowledge graph (gitignored)
│   ├── index.db            # Database principal
│   ├── embeddings/         # Embeddings semânticos
│   └── cache/              # Cache de análises
├── .gitnexusignore         # Arquivos a ignorar na indexação
├── AGENTS.md               # Entry point para AI assistants
├── CLAUDE.md               # Alias de AGENTS.md
├── memory-bank/
│   ├── gitnexus.md         # Documentação e workflows GitNexus
│   └── systemPatterns.md   # Inclui seção GitNexus
├── .clinerules             # Inclui seção GitNexus Integration
└── .claude/skills/gitnexus/ # Skills especializadas (auto-instaladas)
    ├── gitnexus-exploring/
    ├── gitnexus-impact-analysis/
    ├── gitnexus-debugging/
    ├── gitnexus-refactoring/
    ├── gitnexus-cli/
    └── gitnexus-guide/
```

---

## 📄 Licença

Este framework de integração GitNexus é open-source e pode ser usado livremente em qualquer projeto.

**GitNexus CLI:** Verificar licença em https://github.com/gitnexus/gitnexus

---

## 🤝 Contribuindo

Encontrou melhorias para este guia? Contribua:

1. Fork o projeto
2. Atualize `GITNEXUS.md`
3. Envie um PR com descrição clara das melhorias

---

**Última atualização:** 2026-03-01
**Versão do framework:** 1.0.0
