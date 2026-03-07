#!/usr/bin/env node
"use strict";

const path = require("path");
const sqlite3 = require("sqlite3").verbose();

const DB_PATH = path.join(__dirname, "..", "chat_data.db");

const KNOWN_FUNCTIONS = [
  "mail",
  "whatsapp",
  "get_web",
  "dados",
  "agendar",
  "agendar2",
  "agendar3",
  "listar_agendamentos",
  "apagar_agenda",
  "apagar_agendas_por_tag",
  "apagar_agendas_por_tipo",
  "cancelar_e_agendar2",
  "cancelar_e_agendar3",
  "verificar_disponibilidade",
  "sugerir_horarios",
  "marcar_evento",
  "remarcar_evento",
  "desmarcar_evento",
  "listar_eventos",
  "set_estado",
  "get_estado",
  "set_contexto",
  "get_contexto",
  "limpar_contexto",
  "set_variavel",
  "get_variavel",
  "boomerang",
  "optout",
  "status_followup",
  "log_evento",
  "tempo_sem_interacao",
  "template",
];

function parseArgs(argv) {
  const out = {
    instance: "",
    limit: 0,
    apply: false,
    verbose: false,
  };

  for (let i = 2; i < argv.length; i += 1) {
    const arg = argv[i];
    if (arg === "--apply") {
      out.apply = true;
    } else if (arg === "--verbose") {
      out.verbose = true;
    } else if (arg === "--instance") {
      out.instance = String(argv[i + 1] || "").trim();
      i += 1;
    } else if (arg === "--limit") {
      const n = Number(argv[i + 1]);
      out.limit = Number.isFinite(n) && n > 0 ? Math.floor(n) : 0;
      i += 1;
    } else if (arg === "--help" || arg === "-h") {
      printHelp();
      process.exit(0);
    }
  }

  return out;
}

function printHelp() {
  console.log(`Backfill de metadata.commands para mensagens antigas.

Uso:
  node tools/backfill_commands_metadata.js [--instance <id>] [--limit <n>] [--verbose] [--apply]

Padrão:
  dry-run (não escreve no banco)

Opções:
  --instance <id>   filtra por instance_id
  --limit <n>       processa no máximo n mensagens candidatas
  --verbose         imprime cada mensagem candidata
  --apply           aplica updates no banco
`);
}

function openDb(dbPath) {
  return new sqlite3.Database(dbPath);
}

function all(db, sql, params = []) {
  return new Promise((resolve, reject) => {
    db.all(sql, params, (err, rows) => (err ? reject(err) : resolve(rows || [])));
  });
}

function run(db, sql, params = []) {
  return new Promise((resolve, reject) => {
    db.run(sql, params, function onRun(err) {
      if (err) return reject(err);
      resolve({ changes: this.changes, lastID: this.lastID });
    });
  });
}

function closeDb(db) {
  return new Promise((resolve, reject) => {
    db.close(err => (err ? reject(err) : resolve()));
  });
}

function parseFunctionArgs(rawArgs) {
  if (!rawArgs) return [];
  const args = [];
  let buffer = "";
  let quote = null;
  let escape = false;

  const push = () => {
    const trimmed = buffer.trim();
    args.push(trimmed);
    buffer = "";
  };

  for (let i = 0; i < rawArgs.length; i += 1) {
    const ch = rawArgs[i];
    if (escape) {
      buffer += ch;
      escape = false;
      continue;
    }
    if (ch === "\\") {
      buffer += ch;
      escape = true;
      continue;
    }
    if (quote) {
      if (ch === quote) {
        quote = null;
      }
      buffer += ch;
      continue;
    }
    if (ch === "'" || ch === "\"") {
      quote = ch;
      buffer += ch;
      continue;
    }
    if (ch === ",") {
      push();
      continue;
    }
    buffer += ch;
  }

  if (buffer.trim() !== "" || rawArgs.trim().endsWith(",")) {
    push();
  }

  return args.map(arg => {
    const s = String(arg || "").trim();
    if (
      (s.startsWith("\"") && s.endsWith("\"")) ||
      (s.startsWith("'") && s.endsWith("'"))
    ) {
      return s.slice(1, -1);
    }
    return s;
  });
}

function extractCommandsFromText(text) {
  const content = String(text || "");
  if (!content) return [];

  const escaped = KNOWN_FUNCTIONS.map(name => name.replace(/[.*+?^${}()|[\]\\]/g, "\\$&"));
  const fnGroup = escaped.join("|");
  const regex = new RegExp(`(?:\\[\\[)?\\b(${fnGroup})\\s*\\(([^)]*)\\)(?:\\]\\])?`, "gi");

  const commands = [];
  let match;
  while ((match = regex.exec(content)) !== null) {
    const type = String(match[1] || "").toLowerCase();
    if (!type) continue;
    commands.push({
      type,
      args: parseFunctionArgs(match[2] || ""),
      result: {
        ok: true,
        code: "BACKFILL_INFERRED",
        message: "Reconstruido automaticamente a partir do conteudo historico.",
        data: {
          source: "backfill_commands_metadata",
          confidence: "low",
        },
      },
    });
  }

  return commands;
}

async function main() {
  const args = parseArgs(process.argv);
  const db = openDb(DB_PATH);

  try {
    const where = [
      "role = 'assistant'",
      "direction = 'outbound'",
      "(metadata IS NULL OR TRIM(metadata) = '')",
      "content IS NOT NULL",
      "TRIM(content) <> ''",
    ];
    const params = [];

    if (args.instance) {
      where.push("instance_id = ?");
      params.push(args.instance);
    }

    const limitSql = args.limit > 0 ? ` LIMIT ${args.limit}` : "";
    const sql = `
      SELECT id, instance_id, remote_jid, content
      FROM messages
      WHERE ${where.join(" AND ")}
      ORDER BY id ASC
      ${limitSql}
    `;

    const rows = await all(db, sql, params);
    let candidates = 0;
    let updated = 0;

    for (const row of rows) {
      const commands = extractCommandsFromText(row.content);
      if (!commands.length) {
        continue;
      }
      candidates += 1;
      const metadata = JSON.stringify({
        commands,
        backfilled: true,
        backfill_source: "content_pattern",
        backfill_ts: new Date().toISOString(),
      });

      if (args.verbose) {
        console.log(
          `[candidate] id=${row.id} instance=${row.instance_id} remote=${row.remote_jid} commands=${commands.length}`
        );
      }

      if (args.apply) {
        const result = await run(
          db,
          "UPDATE messages SET metadata = ? WHERE id = ? AND (metadata IS NULL OR TRIM(metadata) = '')",
          [metadata, row.id]
        );
        if (result.changes > 0) {
          updated += result.changes;
        }
      }
    }

    console.log(`mode=${args.apply ? "apply" : "dry-run"}`);
    console.log(`scanned=${rows.length}`);
    console.log(`candidates=${candidates}`);
    console.log(`updated=${updated}`);
  } finally {
    await closeDb(db);
  }
}

main().catch(err => {
  console.error("backfill_commands_metadata failed:", err.message);
  process.exit(1);
});

