# Verificador local de solo lectura; requiere el PHP portable del release y Node.js.
# Ejecutar desde PowerShell: .\scripts\verificar-proyecto.ps1
param([switch]$TestCaptureApi)

$ErrorActionPreference = 'Stop'
$root = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$release = Join-Path $root 'GoldHenPC-Release-v1.0'
$php = Join-Path $release 'runtime\php.exe'
$nodeCommand = Get-Command node -ErrorAction SilentlyContinue
$failures = [System.Collections.Generic.List[string]]::new()

function Report-Check([string]$name, [bool]$ok, [string]$detail = '') {
    if ($ok) {
        Write-Host "[OK] $name" -ForegroundColor Green
    } else {
        Write-Host "[ERROR] $name $detail" -ForegroundColor Red
        $script:failures.Add($name)
    }
}

Report-Check 'PHP portable disponible' (Test-Path -LiteralPath $php)
Report-Check 'Node.js disponible' ($null -ne $nodeCommand)
if (-not (Test-Path -LiteralPath $php) -or $null -eq $nodeCommand) {
    Write-Host 'Instala el runtime portable de PC y Node.js para ejecutar esta revisión.' -ForegroundColor Yellow
    exit 1
}

$phpFiles = @(
    Get-ChildItem (Join-Path $root 'api') -Recurse -File -Filter '*.php'
    Get-ChildItem (Join-Path $root 'modulos') -Recurse -File -Filter '*.php'
    Get-Item (Join-Path $root 'index.php'), (Join-Path $root 'store.php'), (Join-Path $root 'server-router.php')
)
$phpErrors = [System.Collections.Generic.List[string]]::new()
foreach ($file in $phpFiles) {
    $output = & $php -l $file.FullName 2>&1
    if ($LASTEXITCODE -ne 0) { $phpErrors.Add("$($file.FullName): $($output -join ' ')") }
}
Report-Check "PHP syntax ($($phpFiles.Count) files)" ($phpErrors.Count -eq 0) ($phpErrors -join '; ')
$savePathTest = Join-Path $PSScriptRoot 'test-save-paths.php'
$savePathOutput = & $php $savePathTest 2>&1
$savePathPassed = $LASTEXITCODE -eq 0 -and ($savePathOutput -join ' ') -match 'SAVE_PATH_GUARD_OK'
Report-Check 'Guard de rutas de partidas permite CUSA/SLUS y bloquea traversal FTP/ZIP' $savePathPassed ($savePathOutput -join ' ')
$storageSizeTest = Join-Path $PSScriptRoot 'test-storage-size.php'
$storageSizeOutput = & $php $storageSizeTest 2>&1
$storageSizePassed = $LASTEXITCODE -eq 0 -and ($storageSizeOutput -join ' ') -match 'STORAGE_SIZE_SENTINELS_OK'
Report-Check 'Almacenamiento solo declara posible truncamiento en tamaños FTP centinela' $storageSizePassed ($storageSizeOutput -join ' ')

$jsFiles = @(Get-ChildItem (Join-Path $root 'js') -Recurse -File -Filter '*.js')
$jsErrors = [System.Collections.Generic.List[string]]::new()
foreach ($file in $jsFiles) {
    $output = & $nodeCommand.Source --check $file.FullName 2>&1
    if ($LASTEXITCODE -ne 0) { $jsErrors.Add("$($file.FullName): $($output -join ' ')") }
}
Report-Check "JavaScript syntax ($($jsFiles.Count) files)" ($jsErrors.Count -eq 0) ($jsErrors -join '; ')
$audioTest = Join-Path $PSScriptRoot 'test-audio.cjs'
$audioOutput = & $nodeCommand.Source $audioTest 2>&1
$audioPassed = $LASTEXITCODE -eq 0 -and ($audioOutput -join ' ') -match 'AUDIO_SFX_OK'
Report-Check 'Audio reproduce SFX, desbloquea contexto suspendido y muestra estado de prueba' $audioPassed ($audioOutput -join ' ')
$androidStorageScript = Get-Content -LiteralPath (Join-Path $root 'js\android_storage.js') -Raw
$androidStorageTest = Join-Path $PSScriptRoot 'test-android-storage.cjs'
$androidStorageOutput = & $nodeCommand.Source $androidStorageTest 2>&1
$androidStoragePassed = $LASTEXITCODE -eq 0 -and ($androidStorageOutput -join ' ') -match 'ANDROID_STORAGE_BRIDGE_OK'
Report-Check 'APK guarda descargas same-origin en la carpeta SAF elegida y conserva selector Android para enlaces externos' ($androidStoragePassed -and $androidStorageScript -match 'WtaDirectory' -and $androidStorageScript -match 'const relativePath = destinationName' -and (Get-Content -LiteralPath (Join-Path $root 'index.php') -Raw) -match 'android_storage\.js') ($androidStorageOutput -join ' ')

$manifestPath = Join-Path $root 'manifest.json'
$manifest = $null
try { $manifest = Get-Content -LiteralPath $manifestPath -Raw | ConvertFrom-Json -ErrorAction Stop } catch { }
Report-Check 'Nombre del proyecto y PWA usa PS4 Manager Tools' ($null -ne $manifest -and $manifest.name -eq 'PS4 Manager Tools' -and $manifest.short_name -eq 'PS4 Manager')
$iconErrors = [System.Collections.Generic.List[string]]::new()
if ($null -ne $manifest) {
    foreach ($icon in $manifest.icons) {
        $iconPath = Join-Path $root ([string]$icon.src).TrimStart('/')
        if (-not (Test-Path -LiteralPath $iconPath)) { $iconErrors.Add("Falta $($icon.src)"); continue }
        $bytes = [System.IO.File]::ReadAllBytes($iconPath)
        $signature = if ($bytes.Length -ge 8) { ($bytes[0..7] | ForEach-Object { '{0:X2}' -f $_ }) -join '' } else { '' }
        if ($bytes.Length -lt 24 -or $signature -ne '89504E470D0A1A0A') { $iconErrors.Add("PNG inválido: $($icon.src)"); continue }
        $width = ([int]$bytes[16] -shl 24) -bor ([int]$bytes[17] -shl 16) -bor ([int]$bytes[18] -shl 8) -bor [int]$bytes[19]
        $height = ([int]$bytes[20] -shl 24) -bor ([int]$bytes[21] -shl 16) -bor ([int]$bytes[22] -shl 8) -bor [int]$bytes[23]
        $expected = [string]$icon.sizes
        if ($expected -match '^(\d+)x(\d+)$' -and ($width -ne [int]$Matches[1] -or $height -ne [int]$Matches[2])) { $iconErrors.Add("Dimensiones inesperadas: $($icon.src) (${width}x${height})") }
    }
}
Report-Check 'Iconos PWA presentes, PNG válidos y dimensiones correctas' ($iconErrors.Count -eq 0) ($iconErrors -join '; ')

$page = Get-Content -LiteralPath (Join-Path $root 'index.php') -Raw
$moduleMarkup = (Get-ChildItem (Join-Path $root 'modulos') -Recurse -File -Filter '*.php' | ForEach-Object { Get-Content -LiteralPath $_.FullName -Raw }) -join "`n"
$targets = [regex]::Matches($page, "abrirModulo(?:Nativo)?\('([^']+)'\)") | ForEach-Object { $_.Groups[1].Value } | Sort-Object -Unique
$missingTargets = @($targets | Where-Object { $moduleMarkup -notmatch ('id="layer-' + [regex]::Escape($_) + '"') })
Report-Check "Targets de navegación ($($targets.Count))" ($missingTargets.Count -eq 0) ($missingTargets -join ', ')

$requiredMainActions = @(
    @{ Name = 'Partidas'; Pattern = "abrirModulo\('savegames'\)" },
    @{ Name = 'Limpieza'; Pattern = "abrirModuloNativo\('limpieza'\)" },
    @{ Name = 'Almacenamiento'; Pattern = "abrirModuloNativo\('almacenamiento'\)" },
    @{ Name = 'Capturas'; Pattern = "abrirModuloNativo\('capturas'\)" },
    @{ Name = 'Parches de juego'; Pattern = "abrirModuloNativo\('parches-juego'\)" }
)
$missingMainActions = @($requiredMainActions | Where-Object { $page -notmatch $_.Pattern } | ForEach-Object Name)
Report-Check 'Módulos pedidos con acceso independiente en menú principal' ($missingMainActions.Count -eq 0) ($missingMainActions -join ', ')
$launcherCards = ([regex]::Matches($page, 'class="launcher-card')).Count
Report-Check "Launcher desplazable con $launcherCards accesos visibles en código" ($page -match 'id="layer-launcher"[^>]*overflow-y-auto' -and $launcherCards -ge 18)
$searchScript = Get-Content -LiteralPath (Join-Path $root 'js\app.js') -Raw
$searchModuleIds = @('savegames', 'limpieza', 'almacenamiento', 'capturas', 'parches-juego')
$missingSearchModules = @($searchModuleIds | Where-Object { $searchScript -notmatch ("\['[^']+', '[^']+', '" + [regex]::Escape($_) + "'\]") })
Report-Check 'Búsqueda global encuentra los cinco módulos independientes' ($missingSearchModules.Count -eq 0) ($missingSearchModules -join ', ')
$storageApi = Get-Content -LiteralPath (Join-Path $root 'api\saves_api.php') -Raw
$storageJs = Get-Content -LiteralPath (Join-Path $root 'js\almacenamiento.js') -Raw
Report-Check 'Almacenamiento muestra solo juegos/apps, partidas y capturas con vistas previas' ($storageJs -match "'juegos y aplicaciones'" -and $storageJs -match "'partidas'" -and $storageJs -match "'capturas'" -and $storageJs -notmatch 'archivos grandes|archivos más pesados' -and $storageApi -notmatch "categories\['archivos grandes'\]")
$storageRenderTest = Join-Path $PSScriptRoot 'test-almacenamiento-render.cjs'
$storageRenderOutput = & $nodeCommand.Source $storageRenderTest 2>&1
$storageRenderPassed = $LASTEXITCODE -eq 0 -and ($storageRenderOutput -join ' ') -match 'STORAGE_THREE_CATEGORIES_RENDER_OK'
Report-Check 'Almacenamiento renderiza solo las tres categorías solicitadas' $storageRenderPassed ($storageRenderOutput -join ' ')
Report-Check 'Parches tiene capa y apertura nativa directa' ($page -match "abrirModuloNativo\('parches-juego'\)" -and $moduleMarkup -match 'id="layer-parches-juego"')
Report-Check 'Parches inicia lectura después de activar la capa y la página invalida scripts cacheados' ($page -match "(?s)target\.classList\.remove\('hidden'\).*?target\.classList\.add\('active', 'flex'\).*?idModulo === 'parches-juego'.*?parchesRecargar\(\)" -and $page -match 'app_asset_url\(''js/parches_juego\.js''\)' -and $page -match 'Cache-Control: no-store')
$patchModule = Get-Content -LiteralPath (Join-Path $root 'modulos\parches_juego.php') -Raw
$patchController = Get-Content -LiteralPath (Join-Path $root 'js\parches_juego.js') -Raw
Report-Check 'Parches muestra conexión FTP, diagnóstico de estado y error con reintento' ($patchModule -match 'id="patch-connection"' -and $patchController -match 'FTP \$\{current\.host_ip\}:\$\{current\.port\}' -and $patchController -match 'Reintentar' -and $patchController -match 'respuesta inválida del servidor')
$serviceWorker = Get-Content -LiteralPath (Join-Path $root 'sw.js') -Raw
Report-Check 'Service Worker no conserva HTML, scripts o APIs obsoletos' ($serviceWorker -match 'e\.respondWith\(fetch\(e\.request\)\)' -and $serviceWorker -notmatch 'goldhen-v[0-9]+-cache|caches\.(open|match|add)')
$apiFilesForGuard = @(Get-ChildItem -LiteralPath (Join-Path $root 'api') -File -Filter '*.php' | Where-Object { $_.Name -notin @('request_guard.php', 'cache_helpers.php', 'binloader_client.php', 'save_paths.php', 'patches_ini_helpers.php', 'patches_version_helpers.php', 'storage_helpers.php', 'library_listing_helpers.php', 'ftp_compat.php') })
$unguardedApiFiles = @($apiFilesForGuard | Where-Object { (Get-Content -LiteralPath $_.FullName -Raw) -notmatch "require_once __DIR__ \. '/request_guard\.php';" } | ForEach-Object Name)
$guardSource = Get-Content -LiteralPath (Join-Path $root 'api\request_guard.php') -Raw
$routerSource = Get-Content -LiteralPath (Join-Path $root 'server-router.php') -Raw
Report-Check 'Todos los endpoints API y el router aplican guard de origen local' ($unguardedApiFiles.Count -eq 0 -and $guardSource -match 'HTTP_ORIGIN' -and $guardSource -match 'ps4_manager_is_local_host' -and $routerSource -match "api/request_guard\.php") ($unguardedApiFiles -join ', ')
$projectVersion = Get-Content -LiteralPath (Join-Path $root 'project-version.json') -Raw | ConvertFrom-Json
$androidReleaseApi = Get-Content -LiteralPath (Join-Path $root 'api\android_release_api.php') -Raw
$androidReleaseScript = Get-Content -LiteralPath (Join-Path $root 'js\app.js') -Raw
Report-Check 'Actualizador Android usa versionado del proyecto, APK de Releases oficiales y enlace HTTPS validado' ($projectVersion.version -eq '4.5.11' -and $projectVersion.repository -eq 'AJfiles/PS4-MANAGER-TOOL-AJ' -and $androidReleaseApi -match "require_once __DIR__ \. '/request_guard\.php'" -and $androidReleaseApi -match 'browser_download_url' -and $androidReleaseApi -match 'github\.com' -and $androidReleaseApi -match 'version_compare' -and $androidReleaseApi -match 'sha256' -and $androidReleaseScript -match 'android_release_api\.php' -and $androidReleaseScript -match 'Descargar APK' -and $androidReleaseScript -match "url\.protocol !== 'https:'" -and $androidReleaseScript -match 'AJfiles/PS4-MANAGER-TOOL-AJ/releases/download')
$androidReleaseTransportTest = Join-Path $PSScriptRoot 'test-android-release-transport.php'
$androidReleaseTransportOutput = & $php $androidReleaseTransportTest 2>&1
$androidReleaseTransportPassed = $LASTEXITCODE -eq 0 -and ($androidReleaseTransportOutput -join ' ') -match 'ANDROID_RELEASE_TRANSPORT_FASTPATH_OK'
Report-Check 'Actualizador Android prioriza cURL TLS con timeouts breves y conserva fallback HTTPS' $androidReleaseTransportPassed ($androidReleaseTransportOutput -join ' ')
$libraryListingTest = Join-Path $PSScriptRoot 'test-library-appmeta-listing.php'
$libraryListingOutput = & $php $libraryListingTest 2>&1
$libraryListingPassed = $LASTEXITCODE -eq 0 -and ($libraryListingOutput -join ' ') -match 'LIBRARY_APPMETA_LISTING_OK'
Report-Check 'Biblioteca interpreta LIST Unix, NLST, directorios y entradas no válidas' $libraryListingPassed ($libraryListingOutput -join ' ')
$guardTest = Join-Path $PSScriptRoot 'test-api-request-guard.cjs'
$guardTestOutput = & $nodeCommand.Source $guardTest $php $root 2>&1
$guardTestPassed = $LASTEXITCODE -eq 0 -and ($guardTestOutput -join ' ') -match 'API_ORIGIN_GUARD_OK'
Report-Check 'Guard bloquea POST cruzado y permite POST del origen local sin router' $guardTestPassed ($guardTestOutput -join ' ')
Report-Check 'Capturas fuerza lectura fresca al abrir' ($page -match "capturasCargar\(true\)")
$captureControllerSource = Get-Content -LiteralPath (Join-Path $root 'js\capturas.js') -Raw
$libraryControllerSource = Get-Content -LiteralPath (Join-Path $root 'js\biblioteca.js') -Raw
Report-Check 'Galería, descarga y borrado de capturas viven en su módulo independiente' ($captureControllerSource -match 'function renderGallery' -and $captureControllerSource -match 'download_selected' -and $captureControllerSource -match 'delete_capture' -and $libraryControllerSource -notmatch '(?i)captura|screenshot|galeria|gallery')
Report-Check 'Limpieza evita clasificar guardados como inútiles' ($page -notmatch 'Datos huérfanos' -and (Get-Content -LiteralPath (Join-Path $root 'js\limpieza.js') -Raw) -match 'partidas de juegos no instalados')
$saveWarning = Get-Content -LiteralPath (Join-Path $root 'modulos\saves.php') -Raw
Report-Check 'SaveGames distingue su ZIP de respaldo del formato de importación Apollo' ($saveWarning -match 'no es una exportación directa para Apollo' -and $saveWarning -match 'HDD Saves' -and $saveWarning -match 'Copy save game' -and $saveWarning -match 'PS4/APOLLO')
Report-Check 'Título visible del proyecto usa PS4 Manager Tools' ($page -match '<title>PS4 Manager Tools \| Consola PS4')
$settingsMarkup = Get-Content -LiteralPath (Join-Path $root 'modulos\ajustes.php') -Raw
$appScript = Get-Content -LiteralPath (Join-Path $root 'js\app.js') -Raw
Report-Check 'APK Android oculta instalación PWA y reconoce el servidor WebToApp local' ($appScript -match 'ocultarInstalacionPWAEnAPK' -and $appScript -match "location\.port === '18500'" -and $appScript -match 'btn-instalar-pwa')
Report-Check 'Audio SFX responde al primer toque y libera WebAudio' ($appScript -match 'desbloquearAudioInterfaz' -and $appScript -match "addEventListener\('pointerdown', evento" -and $appScript -match "addEventListener\('keydown', desbloquearAudioInterfaz" -and $appScript -match "emitirEfectoSonidoNativo\('click'\)" -and $appScript -match 'globalAudioUnlock')
$updateApi = Get-Content -LiteralPath (Join-Path $root 'api\update_api.php') -Raw
Report-Check 'Actualizador de PC ZIP verifica versión, enlace de Releases y SHA-256' ($updateApi -match 'get_pc_release' -and $updateApi -match 'SHA256SUMS-' -and $updateApi -match 'PS4-Manager-Tools-AJ-PC-v' -and $updateApi -match 'download_required')
$customizationChecks = @(
    (($settingsMarkup -match 'cfg-launcher-vertical') -and ($appScript -match 'cfg_launcher_vertical'))
    (($settingsMarkup -match 'cfg-launcher-shape') -and ($appScript -match 'cfg_launcher_shape'))
    (($settingsMarkup -match 'cfg-launcher-motion') -and ($appScript -match 'cfg_launcher_motion'))
    (($settingsMarkup -match 'cfg-launcher-icons') -and ($appScript -match 'cfg_launcher_icons'))
    (($settingsMarkup -match 'cfg-launcher-theme') -and ($appScript -match 'cfg_launcher_theme'))
    (($settingsMarkup -match 'cfg-device-layout') -and ($appScript -match 'cfg_device_layout'))
)
Report-Check 'Ajustes ofrece y persiste seis preferencias de vista del menú' (@($customizationChecks | Where-Object { -not $_ }).Count -eq 0)
$introMarkup = Get-Content -LiteralPath (Join-Path $root 'modulos\intros.php') -Raw
Report-Check 'Animaciones de inicio usan PS4 Manager Tools' ($introMarkup -match 'PS4 Manager Tools|PS4 MANAGER' -and $introMarkup -notmatch '(?i)GoldHen|GOLDHEN')
$preferenceTest = Join-Path $PSScriptRoot 'test-preferencias-menu.cjs'
$preferenceOutput = & $nodeCommand.Source $preferenceTest 2>&1
$preferenceTestPassed = $LASTEXITCODE -eq 0 -and ($preferenceOutput -join ' ') -match 'LAUNCHER_PREFERENCES_OK'
Report-Check 'Interacción de preferencias del menú con DOM simulado' $preferenceTestPassed ($preferenceOutput -join ' ')
$captureDownloadTest = Join-Path $PSScriptRoot 'test-descarga-capturas.cjs'
$captureDownloadOutput = & $nodeCommand.Source $captureDownloadTest 2>&1
$captureDownloadPassed = $LASTEXITCODE -eq 0 -and ($captureDownloadOutput -join ' ') -match 'CAPTURE_ZIP_DOWNLOAD_OK'
Report-Check 'Descarga ZIP de capturas seleccionadas con DOM simulado' $captureDownloadPassed ($captureDownloadOutput -join ' ')
$libraryRenderTest = Join-Path $PSScriptRoot 'test-biblioteca-safe-render.cjs'
$libraryRenderOutput = & $nodeCommand.Source $libraryRenderTest 2>&1
$libraryRenderPassed = $LASTEXITCODE -eq 0 -and ($libraryRenderOutput -join ' ') -match 'BIBLIOTECA_DYNAMIC_CONTENT_SAFE_OK'
Report-Check 'Biblioteca trata metadatos e imágenes como datos, no HTML/JavaScript' $libraryRenderPassed ($libraryRenderOutput -join ' ')
$captureScript = Get-Content -LiteralPath (Join-Path $root 'js\capturas.js') -Raw
$capturePage = Get-Content -LiteralPath (Join-Path $root 'modulos\capturas.php') -Raw
$captureApi = Get-Content -LiteralPath (Join-Path $root 'api\ps4_screenshots_api.php') -Raw
Report-Check 'Capturas ofrece ZIP por selección, restringe rutas e informa metadatos sin imagen' ($capturePage -match 'descargarSeleccionCapturas\(\)' -and $captureScript -match "request\('download_selected'" -and $captureApi -match 'if \(\$action === ''download_selected''\)' -and $captureApi -match "'/user/av_contents/photo/'" -and $captureApi -match 'metadata_files_count' -and $captureApi -match 'archivos auxiliares \.dat/\.ext')
$cleanupPage = Get-Content -LiteralPath (Join-Path $root 'modulos\limpieza.php') -Raw
$cleanupScript = Get-Content -LiteralPath (Join-Path $root 'js\limpieza.js') -Raw
Report-Check 'Limpieza expone selección total, respaldos y borrado con confirmación simple' ($cleanupPage -match 'limpiezaSeleccionarTodo\(\)' -and $cleanupPage -match 'limpiezaRespaldarDescargar\(\)' -and $cleanupPage -match 'limpiezaBorrarSeleccion\(true\)' -and $cleanupPage -match 'limpiezaBorrarSeleccion\(false\)' -and $cleanupScript -match 'confirm\(' -and $cleanupScript -notmatch 'prompt\s*\(')
$saveController = Get-Content -LiteralPath (Join-Path $root 'js\saves.js') -Raw
$saveApi = Get-Content -LiteralPath (Join-Path $root 'api\saves_api.php') -Raw
Report-Check 'SaveGames permite borrado directo solo con bandera explícita de confirmación' ($saveController -match 'delete_permanent' -and $saveController -match "permanent: '1'" -and $saveApi -match 'sm_is_explicit_permanent_save_delete' -and $saveApi -match 'delete_permanent.*permanent')
Report-Check 'SaveGames completa títulos e iconos de juegos instalados desde el app.db de solo lectura' ($saveController -match 'enrichTitlesFromAppDb' -and $saveController -match 'organizador_apps_api\.php' -and $saveController -match 'app\.titleId' -and $saveController -match 'organizador_icon_api\.php')
$saveTitleTest = Join-Path $PSScriptRoot 'test-saves-title-enrichment.cjs'
$saveTitleOutput = & $nodeCommand.Source $saveTitleTest 2>&1
$saveTitlePassed = $LASTEXITCODE -eq 0 -and ($saveTitleOutput -join ' ') -match 'SAVES_APPDB_TITLE_ENRICHMENT_OK'
Report-Check 'SaveGames integra nombre e icono app.db y conserva fallback para partidas no instaladas' $saveTitlePassed ($saveTitleOutput -join ' ')
Report-Check 'Limpieza usa confirmación simple de borrado irreversible sin frase manual' ($cleanupScript -match 'confirm\(' -and $cleanupScript -notmatch 'prompt\s*\(' -and $cleanupScript -match 'permanente')
$storageCapacity = $storageApi -match "'capacity_bytes'=>null" -and $storageApi -match "'free_bytes'=>null"
Report-Check 'Almacenamiento declara capacidad libre desconocida y desglose base/update/DLC' ($storageCapacity -and $storageApi -match "'base_bytes'=>" -and $storageApi -match "'update_bytes'=>" -and $storageApi -match "'dlc_bytes'=>")
$organizerPage = Get-Content -LiteralPath (Join-Path $root 'modulos\organizador_apps.php') -Raw
Report-Check 'Organizar Apps destaca el selector de perfil y muestra avatar/fallback' ($organizerPage -match 'id="organizador-profile-avatar"' -and $organizerPage -match 'Perfil activo de PS4')
$organizerIconApi = Get-Content -LiteralPath (Join-Path $root 'api\organizador_profile_icon_api.php') -Raw
$organizerScript = Get-Content -LiteralPath (Join-Path $root 'js\organizador_apps.js') -Raw
Report-Check 'Avatar de perfil valida ID, lee caché GoldHEN 0x<ID>, verifica PNG y conserva fallback' ($organizerIconApi -match "\^\[a-f0-9\]\{8\}\$" -and $organizerIconApi -match 'cache/profile/0x' -and $organizerIconApi -match '89504e470d0a1a0a|\\x89PNG' -and $organizerScript -match 'organizador_profile_icon_api\.php' -and $organizerScript -match 'oaProfileAvatar')
$captureController = Get-Content -LiteralPath (Join-Path $root 'js\capturas.js') -Raw
Report-Check 'Capturas ofrece seleccionar, descargar ZIP y eliminar selección' ($capturePage -match 'seleccionarTodasCapturas\(\)' -and $capturePage -match 'descargarSeleccionCapturas\(\)' -and $capturePage -match 'eliminarSeleccionCapturas\(\)' -and $captureController -match 'window\.eliminarSeleccionCapturas\s*=')
$patchRenderTest = Join-Path $PSScriptRoot 'test-parches-render.cjs'
$patchRenderOutput = & $nodeCommand.Source $patchRenderTest 2>&1
$patchRenderPassed = $LASTEXITCODE -eq 0 -and ($patchRenderOutput -join ' ') -match 'PATCHES_RENDER_OK'
Report-Check 'Parches renderiza juegos y parches recibidos en DOM simulado' $patchRenderPassed ($patchRenderOutput -join ' ')
$patchIniTest = Join-Path $PSScriptRoot 'test-patches-ini.php'
$patchIniOutput = & $php $patchIniTest 2>&1
$patchIniPassed = $LASTEXITCODE -eq 0 -and ($patchIniOutput -join ' ') -match 'PATCHES_INI_STATE_OK'
Report-Check 'Parches detecta game_patch.prx=false y edita solo [default]' $patchIniPassed ($patchIniOutput -join ' ')
$patchVersionTest = Join-Path $PSScriptRoot 'test-patches-version.php'
$patchVersionOutput = & $php $patchVersionTest 2>&1
$patchVersionPassed = $LASTEXITCODE -eq 0 -and ($patchVersionOutput -join ' ') -match 'PATCH_VERSION_COMPATIBILITY_OK'
Report-Check 'Parches compara versiones exactas y clasifica mask/desconocidas' $patchVersionPassed ($patchVersionOutput -join ' ')
$i18nSource = Get-Content -LiteralPath (Join-Path $root 'js\i18n.js') -Raw
Report-Check 'Ajustes permite elegir español o inglés y traduce controles dinámicos' ($settingsMarkup -match 'cfg-language' -and $i18nSource -match 'MutationObserver' -and $page -match 'js/i18n.js')
$i18nTest = Join-Path $PSScriptRoot 'test-i18n.cjs'
$i18nOutput = & $nodeCommand.Source $i18nTest 2>&1
$i18nPassed = $LASTEXITCODE -eq 0 -and ($i18nOutput -join ' ') -match 'I18N_LANGUAGE_SWITCH_OK'
Report-Check 'Cambio de idioma conserva y restaura el texto original' $i18nPassed ($i18nOutput -join ' ')
$i18nAudit = Join-Path $PSScriptRoot 'audit-i18n-static.cjs'
$i18nAuditOutput = & $nodeCommand.Source $i18nAudit 2>&1
$i18nAuditPassed = $LASTEXITCODE -eq 0 -and ($i18nAuditOutput -join "`n") -match 'STATIC_UNTRANSLATED_CANDIDATES=0'
Report-Check 'Auditor inglés sin textos de interfaz estáticos pendientes' $i18nAuditPassed ($i18nAuditOutput -join ' ')
$loaderTest = Join-Path $PSScriptRoot 'test-loaders.cjs'
$loaderOutput = & $nodeCommand.Source $loaderTest 2>&1
$loaderPassed = $LASTEXITCODE -eq 0 -and ($loaderOutput -join ' ') -match 'CUSTOM_LOADERS_PREVIEW_OK'
Report-Check 'Loaders personalizables generan cuatro estilos y vista previa' $loaderPassed ($loaderOutput -join ' ')
$launcherPreviewMarkup = Get-Content -LiteralPath (Join-Path $root 'modulos\ajustes.php') -Raw
$launcherPreviewStyles = Get-Content -LiteralPath (Join-Path $root 'index.php') -Raw
$launcherPreviewController = Get-Content -LiteralPath (Join-Path $root 'js\app.js') -Raw
Report-Check 'Vista previa del launcher comparte clases de forma, tema, movimiento y orientación' ($launcherPreviewMarkup -match 'id="launcher-preview-grid"' -and $launcherPreviewMarkup -match 'id="launcher-preview-panel"' -and $launcherPreviewStyles -match ':is\(#launcher-grid, #launcher-preview-grid\)\.shape-hexagon' -and $launcherPreviewController -match "getElementById\('launcher-preview-grid'\)" -and $launcherPreviewController -match 'grid\.classList\.add\(`theme-\$\{selected\}`\)')
$previewSelection = $launcherPreviewController.Substring($launcherPreviewController.IndexOf('function seleccionarFondoCustom('))
$wallpaperPreview = Get-Content -LiteralPath (Join-Path $root 'modulos\wallpapers.php') -Raw
$introPreview = Get-Content -LiteralPath (Join-Path $root 'modulos\intros.php') -Raw
Report-Check 'Fondos e intros se previsualizan y requieren aplicar para persistir' ($previewSelection -match 'changeDynamicWallpaper\(idFondo, false\)' -and $previewSelection -match 'function aplicarPrevisualizacionVisual' -and $previewSelection -match 'function cancelarPrevisualizacionVisual' -and $wallpaperPreview -match 'changeDynamicWallpaper\(bgId, persist = true\)' -and $introPreview -match 'window\.cancelarIntroTemporal' -and $launcherPreviewMarkup -match 'onclick="aplicarPrevisualizacionVisual\(\)"')
$customPreviewTest = Join-Path $PSScriptRoot 'test-custom-previews.cjs'
$customPreviewOutput = & $nodeCommand.Source $customPreviewTest 2>&1
$customPreviewPassed = $LASTEXITCODE -eq 0 -and ($customPreviewOutput -join ' ') -match 'CUSTOM_THEME_INTRO_PREVIEWS_OK'
Report-Check 'Previsualizar fondo/intro y confirmar o cancelar sin persistir antes' $customPreviewPassed ($customPreviewOutput -join ' ')
$captureSingleView = $capturePage -notmatch 'modal-galeria-juego' -and $captureScript -notmatch 'modal-galeria-juego' -and $capturePage -match 'id="layer-capturas"'
Report-Check 'Capturas usa una sola pantalla de galería sin panel duplicado' $captureSingleView
$cleanupRenderTest = Join-Path $PSScriptRoot 'test-limpieza-render.cjs'
$cleanupRenderOutput = & $nodeCommand.Source $cleanupRenderTest 2>&1
$cleanupRenderPassed = $LASTEXITCODE -eq 0 -and ($cleanupRenderOutput -join ' ') -match 'CLEANUP_PROFILE_AND_DELETE_FLOWS_OK'
Report-Check 'Limpieza muestra perfiles, ZIP y distingue borrado protegido/permanente' $cleanupRenderPassed ($cleanupRenderOutput -join ' ')
if ($TestCaptureApi) {
    $captureApiTest = Join-Path $PSScriptRoot 'test-descarga-capturas-api.cjs'
    $captureApiOutput = & $nodeCommand.Source $captureApiTest 2>&1
    $captureApiPassed = $LASTEXITCODE -eq 0 -and ($captureApiOutput -join ' ') -match 'CAPTURE_ZIP_API_FIXTURE_OK'
    Report-Check 'API genera ZIP, elimina y verifica captura por FTP; rechaza ruta traversal' $captureApiPassed ($captureApiOutput -join ' ')
}

$sourceRoots = @('api', 'js', 'modulos', 'payloads', 'payloads fw', 'plugins')
$mirrorErrors = [System.Collections.Generic.List[string]]::new()
foreach ($relativeRoot in $sourceRoots) {
    foreach ($file in Get-ChildItem -LiteralPath (Join-Path $root $relativeRoot) -Recurse -File) {
        $relative = $file.FullName.Substring($root.Length + 1)
        $destination = Join-Path $release $relative
        if (-not (Test-Path -LiteralPath $destination)) { $mirrorErrors.Add("Falta en PC: $relative"); continue }
        if ((Get-FileHash -Algorithm SHA256 -LiteralPath $file.FullName).Hash -ne (Get-FileHash -Algorithm SHA256 -LiteralPath $destination).Hash) { $mirrorErrors.Add("Difiere en PC: $relative") }
    }
}
$rootFiles = @('index.php', 'manifest.json', 'README.md', 'MEJORAS_IMPLEMENTADAS.txt', 'server-router.php', 'store.php', 'sw.js')
foreach ($relative in $rootFiles) {
    $source = Join-Path $root $relative
    $destination = Join-Path $release $relative
    if (-not (Test-Path -LiteralPath $destination)) { $mirrorErrors.Add("Falta en PC: $relative"); continue }
    if ((Get-FileHash -Algorithm SHA256 -LiteralPath $source).Hash -ne (Get-FileHash -Algorithm SHA256 -LiteralPath $destination).Hash) { $mirrorErrors.Add("Difiere en PC: $relative") }
}
Report-Check 'Sincronización de archivos distribuidos con PC' ($mirrorErrors.Count -eq 0) ($mirrorErrors -join '; ')

if ($failures.Count) {
    Write-Host "Revisión terminada con $($failures.Count) comprobación(es) fallida(s)." -ForegroundColor Red
    exit 1
}
Write-Host 'Revisión completada sin errores.' -ForegroundColor Green
