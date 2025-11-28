@echo off
REM ====================================================================
REM Script de vérification du code de sauvegarde (import et restauration)
REM ====================================================================

echo.
echo ================================================================
echo   VERIFICATION DU CODE DE SAUVEGARDE
echo   (Import et Restauration)
echo ================================================================
echo.

set "FILE=app\Http\Controllers\Admin\BackupController.php"

if not exist "%FILE%" (
    echo [ERREUR] Fichier introuvable : %FILE%
    pause
    exit /b 1
)

echo [INFO] Fichier trouve : %FILE%
echo.

REM Vérifier les marqueurs de protection - IMPORT
echo [VERIFICATION] Recherche des marqueurs de protection - IMPORT...
echo.

findstr /C:"SECTION CRITIQUE - IMPORT DE SAUVEGARDE" "%FILE%" >nul
if %errorlevel% neq 0 (
    echo [ERREUR] Marqueur de debut de section IMPORT introuvable !
    echo [ERREUR] Le code d'import a peut-etre ete supprime.
    echo.
    pause
    exit /b 1
) else (
    echo [OK] Marqueur de debut IMPORT trouve
)

findstr /C:"FIN DE LA SECTION CRITIQUE - IMPORT DE SAUVEGARDE" "%FILE%" >nul
if %errorlevel% neq 0 (
    echo [ERREUR] Marqueur de fin de section IMPORT introuvable !
    echo [ERREUR] Le code d'import a peut-etre ete supprime.
    echo.
    pause
    exit /b 1
) else (
    echo [OK] Marqueur de fin IMPORT trouve
)

REM Vérifier la méthode import
echo.
echo [VERIFICATION] Recherche de la methode import()...
echo.

findstr /C:"public function import" "%FILE%" >nul
if %errorlevel% neq 0 (
    echo [ERREUR] Methode import() introuvable !
) else (
    echo [OK] Methode import() trouvee
)

echo.
echo ================================================================
echo   VERIFICATION - RESTAURATION
echo ================================================================
echo.

REM Vérifier les marqueurs de protection - RESTAURATION
echo [VERIFICATION] Recherche des marqueurs de protection - RESTAURATION...
echo.

findstr /C:"SECTION CRITIQUE - RESTAURATION COMPLETE" "%FILE%" >nul
if %errorlevel% neq 0 (
    echo [ERREUR] Marqueur de debut de section critique introuvable !
    echo [ERREUR] Le code de restauration a peut-etre ete supprime.
    echo.
    pause
    exit /b 1
) else (
    echo [OK] Marqueur de debut trouve
)

findstr /C:"FIN DE LA SECTION CRITIQUE - RESTAURATION COMPLETE" "%FILE%" >nul
if %errorlevel% neq 0 (
    echo [ERREUR] Marqueur de fin de section RESTAURATION introuvable !
    echo [ERREUR] Le code de restauration a peut-etre ete supprime.
    echo.
    pause
    exit /b 1
) else (
    echo [OK] Marqueur de fin RESTAURATION trouve
)

REM Vérifier les méthodes critiques
echo.
echo [VERIFICATION] Recherche des methodes critiques...
echo.

findstr /C:"private function findDatabaseDump" "%FILE%" >nul
if %errorlevel% neq 0 (
    echo [ERREUR] Methode findDatabaseDump() introuvable !
) else (
    echo [OK] Methode findDatabaseDump() trouvee
)

findstr /C:"private function restoreDatabase" "%FILE%" >nul
if %errorlevel% neq 0 (
    echo [ERREUR] Methode restoreDatabase() introuvable !
) else (
    echo [OK] Methode restoreDatabase() trouvee
)

findstr /C:"private function restoreFiles" "%FILE%" >nul
if %errorlevel% neq 0 (
    echo [ERREUR] Methode restoreFiles() introuvable !
) else (
    echo [OK] Methode restoreFiles() trouvee
)

findstr /C:"private function deleteDirectory" "%FILE%" >nul
if %errorlevel% neq 0 (
    echo [ERREUR] Methode deleteDirectory() introuvable !
) else (
    echo [OK] Methode deleteDirectory() trouvee
)

findstr /C:"public function restore" "%FILE%" >nul
if %errorlevel% neq 0 (
    echo [ERREUR] Methode restore() introuvable !
) else (
    echo [OK] Methode restore() trouvee
)

echo.
echo ================================================================
echo   VERIFICATION TERMINEE
echo ================================================================
echo.
echo Si toutes les verifications sont OK, le code de sauvegarde
echo (import et restauration) est intact et fonctionnel.
echo.
pause

