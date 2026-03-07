# GitNexus - Knowledge Graph Integration

## Overview
GitNexus indexes this codebase into a knowledge graph with symbols, dependencies, execution flows, and clusters. Use it to prevent breaking changes and understand architectural impact.

## Index Status
**Location:** `.gitnexus/` (gitignored)
**Check freshness:** `gitnexus status`
**Re-index:** `gitnexus analyze`

## Critical Use Cases

### 1. Before Modifying AI System
**Example:** Changing `loadAIConfig()` in `src/whatsapp-server/ai/index.js`
```bash
# Check what depends on this function
gitnexus context --name loadAIConfig
gitnexus impact --target loadAIConfig --direction upstream
```

### 2. Before Renaming Variables/Functions
**Example:** Renaming `global.INSTANCE_ID` to `global.INSTANCE_SESSION_ID`
```bash
# Use GitNexus rename tool (safer than manual find/replace)
gitnexus rename --symbol_name INSTANCE_ID --new_name INSTANCE_SESSION_ID --dry_run
```

### 3. Pre-Commit Validation
```bash
# Before committing changes, check impact
gitnexus detect_changes --scope all
```

### 4. Debugging Call Chains
**Example:** Tracing how AI responses are dispatched
```bash
# Find execution flow from entry point
gitnexus context --name dispatchAIResponse
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
- `src/whatsapp-server/ai/index.js` (AI config, prompt loading)
- `src/whatsapp-server/whatsapp/handlers/messages.js` (message processing)
- `db-updated.js` (database layer)
- `master-server.js` (instance orchestration)

## Skills Installed

| Skill | Path | When to Use |
|-------|------|-------------|
| Exploring | `.claude/skills/gitnexus/gitnexus-exploring/` | Understanding unfamiliar code |
| Impact Analysis | `.claude/skills/gitnexus/gitnexus-impact-analysis/` | Before modifications |
| Debugging | `.claude/skills/gitnexus/gitnexus-debugging/` | Tracing bugs |
| Refactoring | `.claude/skills/gitnexus/gitnexus-refactoring/` | Planning safe refactors |
| CLI | `.claude/skills/gitnexus/gitnexus-cli/` | Index management |
| Guide | `.claude/skills/gitnexus/gitnexus-guide/` | Tool reference |

## Examples from Recent Fixes

### AI Prompt Isolation Fix (March 2026)
**Problem:** Prompts vazando entre instâncias
**GitNexus usage:**
1. `context({name: "loadAIConfig"})` - See all callers
2. `impact({target: "globalSettings", direction: "upstream"})` - Check usage
3. Modified line 249 - removed global inheritance
4. `detect_changes()` - Verified no breaking changes

**Result:** Each instance now uses ONLY its own prompt (no global inheritance)

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

- **Repo Context:** `gitnexus://repo/wpp/context` (stats, staleness, tools)
- **Clusters:** `gitnexus://repo/wpp/clusters` (functional groupings)
- **Processes:** `gitnexus://repo/wpp/processes` (execution flows)
- **Schema:** `gitnexus://repo/wpp/schema` (for Cypher queries)

## Best Practices

1. **Always check index freshness first** - Read context resource
2. **Use impact() before critical changes** - Especially in AI, DB, messaging layers
3. **Prefer rename() tool over manual find/replace** - Safer, graph-aware
4. **Run detect_changes() before commits** - Catch breaking changes early
5. **Consult skills when stuck** - They have detailed workflows for specific tasks
