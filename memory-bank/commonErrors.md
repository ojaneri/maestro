# Common Errors & Lessons Learned

Este documento registra desafios técnicos, erros recorrentes e falhas de ambiente, servindo como guia de mitigação para sessões futuras.

## 📋 Registro de Incidentes

### 1. Erro de Permissão no Webroot
- **Erro:** Erro 403 Forbidden ou falha de escrita em `/var/www/html/`.
- **Causa:** Arquivos criados via CLI assumindo o usuário `root` ou permissão `644`.
- **Como foi resolvido:** Executado `chown -R www-data:webdev` e `chmod -R 775`.
- **Como evitar:** Seguir o "Checkpoint de Execução" nas regras do sistema que exige a correção de permissões imediatamente após cada comando de escrita.

### 2. Conflito de Versão de Dependências
- **Erro:** [Descreva o erro aqui, ex: Falha na biblioteca X após atualização]
- **Causa:** [Ex: Atualização automática sem fixar versão no composer/npm]
- **Como foi resolvido:** [Ex: Rollback e versionamento estrito no arquivo de lock]
- **Como evitar:** Verificar o `techContext.md` antes de rodar comandos de atualização global.

---

## 🛡️ Estratégias de Prevenção Geral
- **Verificação de Permissões:** Antes de finalizar qualquer tarefa, rodar o script de auditoria de owner/group.
- **Fail-Fast:** Implementar logs detalhados em funções críticas para identificar a raiz do erro rapidamente.
- **Consultoria de Contexto:** Sempre ler este arquivo ao iniciar uma nova funcionalidade que toque em partes sensíveis do sistema (Auth, Uploads, DB).
