@echo off
setlocal

REM === CONFIGURAÇÃO ===
set REPO_DIR=C:\Users\Administrator\dev\maestro
set BRANCH=main

REM === ENTRAR NO REPO ===
cd /d "%REPO_DIR%" || (
    echo [ERRO] Nao foi possivel acessar %REPO_DIR%
    exit /b 1
)

REM === VERIFICAR SE EXISTE ALTERACAO ===
git status --porcelain > temp_git_status.txt

for %%A in (temp_git_status.txt) do (
    if %%~zA==0 (
        echo [INFO] Nenhuma alteracao para commitar.
        del temp_git_status.txt
        exit /b 0
    )
)

del temp_git_status.txt

REM === COMMIT ===
set TIMESTAMP=%DATE% %TIME%
git add .
git commit -m "chore: update maestro (%TIMESTAMP%)"

REM === PUSH ===
git push origin %BRANCH%

if %ERRORLEVEL% neq 0 (
    echo [ERRO] Falha no push.
    exit /b 1
)

echo [OK] Alteracoes salvas no Git com sucesso.
endlocal
