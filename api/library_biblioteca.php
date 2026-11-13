<?php
require_once __DIR__ . '/request_guard.php';
/**
 * ====================================================================
 * PS4 Manager Tools 🚀 - API CORE DE LA BIBLIOTECA
 * Mantenido por AJ · Basado en proyecto original de SeBaS
 * RUTA: api/library_biblioteca.php
 * ====================================================================
 */
error_reporting(0);
@ini_set('display_errors', 0);
@ini_set('memory_limit', '512M');
set_time_limit(300);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/cache_helpers.php';
require_once __DIR__ . '/library_listing_helpers.php';
$firma = chr(83).chr(101).chr(66).chr(97).chr(83); 
header('X-Author: ' . $firma);

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$host_ip = $_POST['host_ip'] ?? $_GET['host_ip'] ?? '';
$cusa_id = strtoupper(trim($_POST['cusa_id'] ?? $_GET['cusa_id'] ?? ''));
$port = isset($_POST['port']) ? (int)$_POST['port'] : 2121;

// 🔥 RUTA CORREGIDA HACIA LA NUEVA ESTRUCTURA USER
$cache_dir = __DIR__ . '/../user/cache/biblioteca';
$metadata_cache_file = $cache_dir . '/titles.json';
if (!file_exists($cache_dir)) { 
    @mkdir($cache_dir, 0777, true); 
}
if (!file_exists($cache_dir . '/.nomedia')) { 
    @file_put_contents($cache_dir . '/.nomedia', ''); 
}
limpiar_cache_antigua($cache_dir);

$db_categorias_file = $cache_dir . '/custom_categories.json';
$custom_cats = [];
if (file_exists($db_categorias_file)) {
    $custom_cats = json_decode(@file_get_contents($db_categorias_file), true) ?: [];
}

/**
 * PARSEADOR SFO ORIGINAL DE SEBAS (RESTAURADO PARA NOMBRES EXACTOS)
 * Mejorado con límites de longitud para evitar bucles infinitos
 */
function parse_sfo_real($filepath) {
    if (!file_exists($filepath) || @filesize($filepath) === 0) {
        return false;
    }
    
    $buffer = @file_get_contents($filepath);
    if (!$buffer) return false;
    
    $sfo_pos = strpos($buffer, "\0PSF");
    if ($sfo_pos !== false) {
        $sfo_data = substr($buffer, $sfo_pos, 65536);
        $magic = substr($sfo_data, 0, 4);
        if ($magic === "\0PSF") {
            $meta = [];
            $key_table_offset = unpack('V', substr($sfo_data, 8, 4))[1];
            $data_table_offset = unpack('V', substr($sfo_data, 12, 4))[1];
            $entries = unpack('V', substr($sfo_data, 16, 4))[1];
            
            for ($i = 0; $i < $entries; $i++) {
                $entry_offset = 20 + ($i * 16);
                if ($entry_offset + 16 > strlen($sfo_data)) break;
                $key_offset = unpack('v', substr($sfo_data, $entry_offset, 2))[1];
                $data_len = unpack('V', substr($sfo_data, $entry_offset + 4, 4))[1];
                $data_offset = unpack('V', substr($sfo_data, $entry_offset + 12, 4))[1];
                
                $key = '';
                $pos = $key_table_offset + $key_offset;
                while ($pos < strlen($sfo_data) && $sfo_data[$pos] !== "\0") {
                    $key .= $sfo_data[$pos];
                    $pos++;
                }
                
                if ($key === 'TITLE' || $key === 'APP_TITLE' || $key === 'APP_VER' || $key === 'CATEGORY') {
                    $val = substr($sfo_data, $data_table_offset + $data_offset, $data_len);
                    $val_clean = trim(str_replace("\0", '', $val));
                    $meta[$key] = preg_replace('/[\x00-\x1F\x7F]/u', '', $val_clean);
                }
            }
            return $meta;
        }
    }
    return false;
}

/**
 * Descarga un archivo desde FTP con reintentos si falla
 */
function curl_download_ftp_file($ip, $port, $remote_path, $local_path, $max_attempts = 2) {
    $attempt = 0;
    while ($attempt < $max_attempts) {
        if (lb_download_ftp_file((string)$ip, (int)$port, (string)$remote_path, (string)$local_path)) return true;
        $attempt++;
        usleep(200000); // Esperar 200ms entre reintentos
    }
    return false;
}

function clean_title_sfo($parsed_array, $cusa) {
    if (!$parsed_array) return $cusa;
    $title = $parsed_array['TITLE'] ?? $parsed_array['APP_TITLE'] ?? $cusa;
    if (preg_match('/^\d+$/', trim($title))) { return $cusa; }
    return trim(preg_replace('/\s+/', ' ', $title));
}

function library_custom_cover_url($titleId) {
    $safeId = strtoupper((string)$titleId);
    if (!preg_match('/^[A-Z0-9._-]{1,32}$/', $safeId)) return '';
    foreach (['jpg', 'jpeg', 'png'] as $extension) {
        $relative = 'user/portadas_custom/' . $safeId . '.' . $extension;
        $path = __DIR__ . '/../' . $relative;
        if (is_file($path) && @filesize($path) > 0) {
            return $relative . '?v=' . (string)@filemtime($path);
        }
    }
    return '';
}

// =======================================================
// ACCIÓN: OBTENER JUEGOS EN CACHÉ
// =======================================================
if ($action === 'get_cached_games') {
    $games = [];
    $metadata_cache = [];
    if (is_file($metadata_cache_file)) {
        $decoded_metadata = json_decode((string)@file_get_contents($metadata_cache_file), true);
        if (is_array($decoded_metadata)) $metadata_cache = $decoded_metadata;
    }
    foreach ($metadata_cache as $id => $cached_game) {
        if (!is_array($cached_game) || !preg_match('/^[A-Z0-9]{8,16}$/i', (string)$id)) continue;
        $cached_game['id'] = strtoupper((string)$id);
        $cached_game['installed'] = null;
        $cached_icon = $cache_dir . '/' . $cached_game['id'] . '.png';
        $cached_game['img'] = is_file($cached_icon) && @filesize($cached_icon) > 0
            ? 'user/cache/biblioteca/' . $cached_game['id'] . '.png?v=' . @filemtime($cached_icon)
            : '';
        $cached_game['cover3d'] = library_custom_cover_url($cached_game['id']);
        $games[$cached_game['id']] = $cached_game;
    }
    $sfos_locales = glob($cache_dir . '/*.sfo');
    
    if (is_array($sfos_locales)) {
        foreach ($sfos_locales as $sfo_path) {
            $cusa = pathinfo($sfo_path, PATHINFO_FILENAME);
            if (preg_match('/^[A-Z]{4}\d{5}$/i', $cusa) || $cusa === 'APOLO0004' || strpos($cusa, 'LAPY') === 0 || strpos($cusa, 'NPXS') === 0) {
                $icon_path = $cache_dir . '/' . $cusa . '.png';
                $title = $cusa; 
                $version = "1.00";
                $cat_key = 'g';
                
                if (@filesize($sfo_path) > 0) {
                    $sfo_data = parse_sfo_real($sfo_path);
                    if ($sfo_data) {
                        $title = clean_title_sfo($sfo_data, $cusa);
                        if (!empty($sfo_data['APP_VER'])) { $version = $sfo_data['APP_VER']; }
                        if (!empty($sfo_data['CATEGORY'])) { $cat_key = $sfo_data['CATEGORY']; }
                    }
                }
                
                $tipo_defecto = (strpos($cat_key, 'g') !== false) ? 'JUEGOS' : 'APPS';
                if (in_array($cusa, ['TOOL00001', 'NPXS29005', 'NPXS30017', 'APOLO0004']) || strpos($cusa, 'LAPY') === 0 || strpos($cusa, 'NPXS') === 0) {
                    $tipo_defecto = 'APPS';
                }
                $tipo_final = $custom_cats[$cusa] ?? $tipo_defecto;

                $games[$cusa] = [
                    'id' => $cusa, 
                    'nombre' => $title, 
                    'tipo' => $tipo_final,
                    'version' => $version,
                    'installed' => null,
                    'size' => '23.5 GB',
                    'img' => 'user/cache/biblioteca/' . $cusa . '.png?v=' . @filemtime($icon_path),
                    'cover3d' => library_custom_cover_url($cusa)
                ];
            }
        }
    }
    $games = array_values($games);
    usort($games, function($a, $b) { return strcasecmp($a['nombre'], $b['nombre']); });
    echo json_encode(['status' => 'success', 'data' => $games]);
    exit;
}

// =======================================================
// ACCIÓN: ESCANEAR CONSOLA (DETECTAR NUEVOS JUEGOS)
// =======================================================
if ($action === 'scan') {
    $rutas_appmeta = [
        "/user/appmeta", "/system_data/priv/appmeta",
        "/user/appmeta/external", "/system_data/priv/appmeta/external"
    ];

    $cusa_detectados = [];
    $lectura_appmeta_completada = false;

    foreach ($rutas_appmeta as $base_path) {
        $directorio_leido = false;
        $cusa_detectados += lb_read_appmeta_directory($host_ip, $port, $base_path, $directorio_leido);
        $lectura_appmeta_completada = $lectura_appmeta_completada || $directorio_leido;
    }

    if (!$lectura_appmeta_completada) {
        http_response_code(502);
        echo json_encode(['status' => 'error', 'message' => 'No se pudo leer ningún directorio de appmeta por FTP. Se conserva la biblioteca local.']);
        exit;
    }

    // appmeta conserva iconos y SFO incluso después de desinstalar un título.
    // El estado real se obtiene de los directorios de instalación, incluidos
    // los montajes de almacenamiento externo. Si FTP no deja leer ninguno,
    // el cliente debe mostrar "Sin verificar" en vez de marcar todo como instalado.
    $ids_instalados = [];
    $lectura_app_interna = false;
    $ids_instalados += lb_read_appmeta_directory($host_ip, $port, '/user/app', $lectura_app_interna);
    $lectura_montajes = false;
    $montajes = lb_read_directory_names($host_ip, $port, '/mnt', $lectura_montajes);
    $lectura_apps_externas = $lectura_montajes;
    if ($lectura_montajes) {
        foreach ($montajes as $montaje) {
            if (!preg_match('/^ext[01]$/i', $montaje)) continue;
            $directorio_leido = false;
            $base_path = '/mnt/' . $montaje . '/user/app';
            $ids_instalados += lb_read_appmeta_directory($host_ip, $port, $base_path, $directorio_leido);
            if (!$directorio_leido) $lectura_apps_externas = false;
        }
    }
    $lectura_apps_completada = $lectura_app_interna && $lectura_apps_externas;

    echo json_encode([
        'status' => 'success',
        'games' => $cusa_detectados,
        'installed_ids' => array_fill_keys(array_keys($ids_instalados), true),
        'installed_verified' => $lectura_apps_completada
    ]);
    exit;
}

// =======================================================
// ACCIÓN: OBTENER DATOS DE UN JUEGO ESPECÍFICO
// =======================================================
if ($action === 'get_game_data') {
    if (!$host_ip || !preg_match('/^[A-Z0-9]{8,16}$/', $cusa_id)) {
        echo json_encode(['status' => 'error', 'message' => 'Faltan IP o CUSA']); 
        exit; 
    }

    $base_route = $_POST['base_path'] ?? $_GET['base_path'] ?? '';
    $rutas_appmeta_permitidas = [
        '/user/appmeta', '/system_data/priv/appmeta',
        '/user/appmeta/external', '/system_data/priv/appmeta/external'
    ];
    if (!in_array(rtrim((string)$base_route, '/'), $rutas_appmeta_permitidas, true)) $base_route = '';
    $local_icon = $cache_dir . '/' . $cusa_id . '.png';
    $local_sfo = $cache_dir . '/' . $cusa_id . '.sfo';

    $has_icon = (file_exists($local_icon) && @filesize($local_icon) > 0);
    $has_sfo = (file_exists($local_sfo) && @filesize($local_sfo) > 0);

    // Si ya tenemos icono y SFO, devolver desde caché
    if ($has_icon && $has_sfo) {
        $parsed = parse_sfo_real($local_sfo);
        if ($parsed) {
            $title = clean_title_sfo($parsed, $cusa_id);
            $version = $parsed['APP_VER'] ?? 'v1.00';
            $category_key = $parsed['CATEGORY'] ?? 'g';
            $tipo_por_defecto = (strpos($category_key, 'g') !== false) ? 'JUEGOS' : 'APPS';
            if ($cusa_id === 'APOLO0004' || strpos($cusa_id, 'LAPY') === 0 || strpos($cusa_id, 'NPXS') === 0) { 
                $tipo_por_defecto = 'APPS'; 
            }
            $tipo_final = $custom_cats[$cusa_id] ?? $tipo_por_defecto;

            echo json_encode([
                'status' => 'success',
                'skipped' => true,
                    'game' => [
                    'id' => $cusa_id,
                    'nombre' => $title,
                    'tipo' => $tipo_final,
                    'version' => $version,
                    'installed' => true,
                    'size' => '23.5 GB',
                    'img' => 'user/cache/biblioteca/' . $cusa_id . '.png?v=' . @filemtime($local_icon),
                    'cover3d' => library_custom_cover_url($cusa_id)
                ]
            ]);
            exit;
        }
    }

    // Intentar descargar SFO si no existe localmente
    if (!$has_sfo) {
        $rutas_sfo_posibles = [];
        if (!empty($base_route)) { 
            $rutas_sfo_posibles[] = rtrim($base_route, '/') . '/' . $cusa_id . '/param.sfo'; 
        }
        $rutas_sfo_posibles = array_merge($rutas_sfo_posibles, [
            "/user/appmeta/$cusa_id/param.sfo",
            "/system_data/priv/appmeta/$cusa_id/param.sfo",
            "/user/appmeta/external/$cusa_id/param.sfo",
            "/system_data/priv/appmeta/external/$cusa_id/param.sfo",
            "/user/app/$cusa_id/sce_sys/param.sfo",
            "/mnt/ext0/user/app/$cusa_id/sce_sys/param.sfo",
            "/mnt/ext1/user/app/$cusa_id/sce_sys/param.sfo"
        ]);
        foreach ($rutas_sfo_posibles as $r_sfo) {
            if (curl_download_ftp_file($host_ip, $port, $r_sfo, $local_sfo)) { 
                $has_sfo = true; 
                break; 
            }
        }
    }

    $parsed = (file_exists($local_sfo) && @filesize($local_sfo) > 0) ? parse_sfo_real($local_sfo) : false;
    if (!$parsed || empty($parsed['TITLE'])) {
        @unlink($local_sfo);
        // appmeta también contiene aplicaciones instaladas que no publican
        // param.sfo por FTP. Su directorio y CUSA ya quedaron verificados por
        // el escaneo; se conserva el título y se intenta obtener icon0.png.
        $parsed = false;
    }

    // Intentar descargar icono si no existe
    if (!$has_icon) {
        $rutas_maestras_iconos = [];
        if (!empty($base_route)) {
            $rutas_maestras_iconos[] = rtrim($base_route, '/') . '/' . $cusa_id . '/icon0.png';
            $rutas_maestras_iconos[] = rtrim($base_route, '/') . '/' . $cusa_id . '/icono.png';
            $rutas_maestras_iconos[] = rtrim($base_route, '/') . '/' . $cusa_id . '/ICON0.PNG';
        }
        $rutas_maestras_iconos = array_merge($rutas_maestras_iconos, [
            "/user/appmeta/$cusa_id/icon0.png",
            "/system_data/priv/appmeta/$cusa_id/icon0.png",
            "/user/appmeta/$cusa_id/icon0.png",
            "/user/appmeta/$cusa_id/icono.png",
            "/user/appmeta/$cusa_id/ICON0.PNG",
            "/system_data/priv/appmeta/$cusa_id/icon0.png",
            "/user/appmeta/external/$cusa_id/icon0.png",
            "/system_data/priv/appmeta/external/$cusa_id/icon0.png",
            "/user/app/$cusa_id/sce_sys/icon0.png",
            "/user/app/$cusa_id/sce_sys/icono.png",
            "/user/app/$cusa_id/sce_sys/ICON0.PNG",
            "/mnt/ext0/user/app/$cusa_id/sce_sys/icon0.png",
            "/mnt/ext1/user/app/$cusa_id/sce_sys/icon0.png"
        ]);
        $rutas_maestras_iconos = array_unique($rutas_maestras_iconos);

        foreach ($rutas_maestras_iconos as $r_icon) {
            if (curl_download_ftp_file($host_ip, $port, $r_icon, $local_icon)) { 
                $has_icon = true; 
                break; 
            }
        }
    }

    $title = clean_title_sfo($parsed, $cusa_id);
    $version = $parsed['APP_VER'] ?? '';
    $category_key = $parsed['CATEGORY'] ?? '';
    
    $tipo_por_defecto = (strpos($category_key, 'g') !== false) ? 'JUEGOS' : 'APPS';
    if (strpos($cusa_id, 'LAPY') === 0 || $cusa_id === 'APOLO0004') { 
        $tipo_por_defecto = 'APPS'; 
    }
    $tipo_final = $custom_cats[$cusa_id] ?? $tipo_por_defecto;

    $final_img = (file_exists($local_icon) && @filesize($local_icon) > 0)
        ? 'user/cache/biblioteca/' . $cusa_id . '.png?v=' . @filemtime($local_icon)
        : '';

    $metadata_cache = [];
    if (is_file($metadata_cache_file)) {
        $decoded_metadata = json_decode((string)@file_get_contents($metadata_cache_file), true);
        if (is_array($decoded_metadata)) $metadata_cache = $decoded_metadata;
    }
    $metadata_cache[$cusa_id] = [
        'id' => $cusa_id,
        'nombre' => $title,
        'tipo' => $tipo_final,
        'version' => $version,
        'metadata_incomplete' => !$parsed
    ];
    @file_put_contents($metadata_cache_file, json_encode($metadata_cache, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);

    echo json_encode([
        'status' => 'success',
        'skipped' => false,
        'game' => [
            'id' => $cusa_id,
            'nombre' => $title,
            'tipo' => $tipo_final,
            'version' => $version,
            'installed' => true,
            'size' => '23.5 GB',
            'img' => $final_img,
            'cover3d' => library_custom_cover_url($cusa_id),
            'metadata_incomplete' => !$parsed
        ]
    ]);
    exit;
}

// =======================================================
// ACCIÓN: CAMBIAR CATEGORÍA DE UN JUEGO
// =======================================================
if ($action === 'cambiar_categoria') {
    $nueva_cat = strtoupper(trim($_POST['categoria'] ?? ''));
    if (!$cusa_id || !$nueva_cat) { 
        echo json_encode(['status' => 'error', 'message' => 'Faltan datos']); 
        exit; 
    }
    $custom_cats[$cusa_id] = $nueva_cat;
    @file_put_contents($db_categorias_file, json_encode($custom_cats, JSON_PRETTY_PRINT));
    echo json_encode(['status' => 'success']);
    exit;
}

// =======================================================
// ACCIÓN: ELIMINAR CATEGORÍA GLOBAL
// =======================================================
if ($action === 'eliminar_categoria_global') {
    $cat_a_borrar = strtoupper(trim($_POST['categoria'] ?? ''));
    if (empty($cat_a_borrar) || in_array($cat_a_borrar, ['TODOS', 'JUEGOS', 'APPS'])) {
        echo json_encode(['status' => 'error', 'message' => 'Categoría protegida o vacía']);
        exit;
    }
    foreach ($custom_cats as $cusa_key => $valor_cat) {
        if ($valor_cat === $cat_a_borrar) { 
            unset($custom_cats[$cusa_key]); 
        }
    }
    @file_put_contents($db_categorias_file, json_encode($custom_cats, JSON_PRETTY_PRINT));
    echo json_encode(['status' => 'success']);
    exit;
}

// =======================================================
// FALLBACK: ACCIÓN NO RECONOCIDA
// =======================================================
echo json_encode(['status' => 'error', 'message' => 'Acción no válida']);
exit;
?>
