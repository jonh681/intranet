<?php
declare(strict_types=1);

/*
 * API de Cotizaciones (JSON). Lecturas por GET; lo que cambia algo, por POST con el token X-CSRF-Token.
 *   productos    GET  marca                    productos de una marca para el cotizador
 *   precio       GET  cliente (id) | mostrador, articulo
 *   enviar       POST tipo, modalidad, cliente, partidas (JSON) y datos del formulario + pdf (y excel en pedidos)
 *   pedido       GET  id                       detalle de un pedido del historial (el propio; el superusuario, cualquiera)
 *   admin_lista     GET  entidad, q, filtro, pagina     (solo superusuario)
 *   admin_guardar   POST entidad, id (vacío = nuevo) y los campos
 *   admin_eliminar  POST entidad, id
 */

require __DIR__ . '/lib.php';
require_once __DIR__ . '/../comun/correo.php';

$user = cotUser($db);
if (!$user) respond(['error' => 'Tu sesión terminó. Vuelve a iniciar sesión.'], 401);
$action = (string)($_GET['action'] ?? $_POST['action'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !hash_equals((string)$_SESSION['csrf'], (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
    respond(['error' => 'La sesión cambió. Recarga la página.'], 403);
}
$soloPost = function (): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(['error' => 'Método no permitido.'], 405);
};

try {
    if ($action === 'productos') {
        $query = $db->prepare("SELECT numero_articulo, descripcion FROM cot_productos WHERE fabricante = ? AND fabricante <> '' ORDER BY descripcion");
        $query->execute([trim((string)($_GET['marca'] ?? ''))]);
        respond(['productos' => $query->fetchAll()]);
    }

    if ($action === 'precio') {
        $cliente = isset($_GET['mostrador']) ? cotClienteMostrador($db) : cotCliente($db, (int)($_GET['cliente'] ?? 0));
        if (!$cliente) respond(['error' => 'Ese cliente ya no está en la lista. Recarga la página.'], 404);
        $query = $db->prepare('SELECT * FROM cot_productos WHERE numero_articulo = ?');
        $query->execute([(string)($_GET['articulo'] ?? '')]);
        $producto = $query->fetch();
        if (!$producto) respond(['error' => 'Ese producto ya no está en la lista. Recarga la página.'], 404);
        respond(['descripcion' => $producto['descripcion'], 'codigo_barras' => $producto['codigo_barras']] + cotPrecio($cliente, $producto));
    }

    if ($action === 'enviar') {
        $soloPost();
        respond(cotEnviar($db, $user));
    }

    if ($action === 'pedido') {
        $query = $db->prepare('SELECT p.*, u.nombre_usuario representante FROM cot_pedidos p JOIN usuarios u ON u.id = p.usuario_id WHERE p.id = ?');
        $query->execute([(int)($_GET['id'] ?? 0)]);
        $pedido = $query->fetch();
        if (!$pedido || ($user['rol'] !== 'admin' && (int)$pedido['usuario_id'] !== $user['id'])) respond(['error' => 'Ese pedido no existe.'], 404);
        $query = $db->prepare('SELECT numero_articulo, descripcion, codigo_barras, cantidad, precio_publico, precio, bonificacion FROM cot_pedido_partidas WHERE pedido_id = ? ORDER BY id');
        $query->execute([$pedido['id']]);
        $partidas = $query->fetchAll();
        // Pedido que salió de esta cotización (enviado), si ya se convirtió.
        $query = $db->prepare("SELECT MIN(id) FROM cot_pedidos WHERE origen_id = ? AND tipo = 'pedido' AND enviado = 1");
        $query->execute([$pedido['id']]);
        $pedido['convertida'] = $query->fetchColumn() ?: null;
        respond(['pedido' => $pedido, 'partidas' => $partidas]);
    }

    if (str_starts_with($action, 'admin_')) {
        if ($user['rol'] !== 'admin') respond(['error' => 'Esta sección es solo para el superusuario.'], 403);
        $entidades = cotEntidades();
        $nombre = (string)($_GET['entidad'] ?? $_POST['entidad'] ?? '');
        if (!isset($entidades[$nombre])) respond(['error' => 'Sección no válida.'], 400);
        $e = $entidades[$nombre];

        if ($action === 'admin_lista') {
            $where = [];
            $params = [];
            $q = trim((string)($_GET['q'] ?? ''));
            if ($q !== '') {
                $where[] = '(' . implode(' OR ', array_map(fn($c) => "CAST($c AS CHAR) LIKE ?", $e['buscar'])) . ')';
                array_push($params, ...array_fill(0, count($e['buscar']), '%' . $q . '%'));
            }
            $filtro = (string)($_GET['filtro'] ?? '');
            if ($filtro !== '') { $where[] = $e['filtro'][0] . ' = ?'; $params[] = $filtro === '(sin marca)' ? '' : $filtro; }
            $sql = ' FROM ' . $e['tabla'] . ($where ? ' WHERE ' . implode(' AND ', $where) : '');
            $count = $db->prepare('SELECT COUNT(*)' . $sql);
            $count->execute($params);
            $total = (int)$count->fetchColumn();
            $porPagina = 25;
            $pagina = max(1, min((int)($_GET['pagina'] ?? 1), (int)ceil(max(1, $total) / $porPagina)));
            $rows = $db->prepare('SELECT *' . $sql . ' ORDER BY ' . $e['orden'] . ' LIMIT ' . $porPagina . ' OFFSET ' . (($pagina - 1) * $porPagina));
            $rows->execute($params);
            $opciones = $db->query('SELECT DISTINCT ' . $e['filtro'][0] . ' FROM ' . $e['tabla'] . ' ORDER BY 1')->fetchAll(PDO::FETCH_COLUMN);
            respond(['filas' => $rows->fetchAll(), 'total' => $total, 'pagina' => $pagina, 'paginas' => (int)ceil(max(1, $total) / $porPagina),
                'opciones' => array_map(fn($o) => $o === '' ? '(sin marca)' : $o, $opciones)]);
        }

        $soloPost();
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $query = $db->prepare('SELECT * FROM ' . $e['tabla'] . ' WHERE id = ?');
            $query->execute([$id]);
            $actual = $query->fetch();
            if (!$actual) respond(['error' => 'Ese registro ya no existe. Recarga la página.'], 404);
        }

        if ($action === 'admin_eliminar') {
            if (!$id) respond(['error' => 'Falta el registro.'], 400);
            if ($nombre === 'clientes' && $actual['nombre'] === COT_CLIENTE_MOSTRADOR) {
                respond(['error' => 'Este cliente calcula los precios de la venta de mostrador: no se puede eliminar.'], 422);
            }
            $db->prepare('DELETE FROM ' . $e['tabla'] . ' WHERE id = ?')->execute([$id]);
            respond(['ok' => true, 'message' => ucfirst($e['singular']) . ' eliminado.']);
        }

        if ($action === 'admin_guardar') {
            $datos = cotValidar($e, $_POST);
            if ($nombre === 'clientes' && $id && $actual['nombre'] === COT_CLIENTE_MOSTRADOR && $datos['nombre'] !== COT_CLIENTE_MOSTRADOR) {
                respond(['error' => 'Este cliente calcula los precios de la venta de mostrador: su nombre no se puede cambiar.'], 422);
            }
            if (isset($e['unico'])) {
                [$col, $mensaje] = $e['unico'];
                $query = $db->prepare('SELECT COUNT(*) FROM ' . $e['tabla'] . " WHERE $col = ? AND id <> ?");
                $query->execute([$datos[$col], $id]);
                if ($query->fetchColumn()) respond(['error' => $mensaje], 422);
            }
            $datos += ['actualizado_en' => date('Y-m-d H:i:s'), 'actualizado_por' => $user['id']];
            $cols = array_keys($datos);
            if ($id) {
                $db->prepare('UPDATE ' . $e['tabla'] . ' SET ' . implode(', ', array_map(fn($c) => "$c = ?", $cols)) . ' WHERE id = ?')
                    ->execute([...array_values($datos), $id]);
            } else {
                $db->prepare('INSERT INTO ' . $e['tabla'] . ' (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')
                    ->execute(array_values($datos));
                $id = (int)$db->lastInsertId();
            }
            respond(['ok' => true, 'id' => $id, 'message' => ucfirst($e['singular']) . ' guardado.']);
        }
    }
    respond(['error' => 'Acción no válida.'], 400);
} catch (InvalidArgumentException $e) {
    respond(['error' => $e->getMessage()], 422);
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('Cotizaciones (' . $action . '): ' . $e->getMessage());
    respond(['error' => 'No se pudo completar la operación. Intenta de nuevo.'], 500);
}

/**
 * Guarda el pedido o la cotización en el historial con los precios del servidor y lo envía por correo:
 * el pedido a Compras (copia al representante), la cotización al cliente. Devuelve la respuesta para el navegador.
 */
function cotEnviar(PDO $db, array $user): array
{
    $tipo = (string)($_POST['tipo'] ?? '');
    $modalidad = (string)($_POST['modalidad'] ?? '');
    if (!isset(COT_REQUERIDOS[$modalidad][$tipo])) throw new InvalidArgumentException('Tipo de envío no válido.');
    $campo = fn(string $k, int $max) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max);
    $form = ['nombre' => $campo('nombre', 150), 'correo' => $campo('correo', 200), 'telefono' => $campo('telefono', 40),
        'direccion' => $campo('direccion', 300), 'comentarios' => $campo('comentarios', 2000)];
    foreach (COT_REQUERIDOS[$modalidad][$tipo] as $k) {
        if ($form[$k] === '') throw new InvalidArgumentException('Faltan datos del formulario. Revisa los campos marcados.');
    }
    if ($form['correo'] !== '' && !filter_var($form['correo'], FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('El correo del cliente no es válido.');

    $cliente = $modalidad === 'mostrador' ? cotClienteMostrador($db) : cotCliente($db, (int)($_POST['cliente'] ?? 0));
    if (!$cliente) throw new InvalidArgumentException('Ese cliente ya no está en la lista. Recarga la página.');
    $nombreCliente = $modalidad === 'mostrador' ? $form['nombre'] : (string)$cliente['nombre'];

    // Cotización que se convierte en pedido (solo una propia, de la misma pestaña y que no se haya convertido ya).
    $origen = null;
    if ($tipo === 'pedido' && (int)($_POST['origen'] ?? 0) > 0) {
        $cotizacion = cotCotizacion($db, $user['id'], (int)$_POST['origen']);
        if (!$cotizacion || $cotizacion['modalidad'] !== $modalidad) throw new InvalidArgumentException('La cotización que estás convirtiendo ya no existe. Usa «Nuevo pedido».');
        if ($cotizacion['convertida']) throw new InvalidArgumentException('Esta cotización ya se convirtió en el pedido #' . $cotizacion['convertida'] . '.');
        $origen = (int)$cotizacion['id'];
    }

    // Partidas: el navegador manda artículo, cantidad y si es bonificación; los precios los pone el servidor.
    $lineas = json_decode((string)($_POST['partidas'] ?? ''), true);
    if (!is_array($lineas) || !$lineas || count($lineas) > 300) throw new InvalidArgumentException('Agrega productos antes de enviar.');
    $buscar = $db->prepare('SELECT * FROM cot_productos WHERE numero_articulo = ?');
    $partidas = [];
    $normales = [];
    foreach ($lineas as $l) {
        $articulo = (string)($l['articulo'] ?? '');
        $cantidad = (int)($l['cantidad'] ?? 0);
        $bonif = !empty($l['bonificacion']);
        if ($cantidad < 1 || $cantidad > 100000) throw new InvalidArgumentException('Revisa las cantidades: deben ser de 1 en adelante.');
        if ($bonif && !isset($normales[$articulo])) throw new InvalidArgumentException('Una bonificación no corresponde a ningún producto del pedido.');
        $buscar->execute([$articulo]);
        $producto = $buscar->fetch();
        if (!$producto) throw new InvalidArgumentException("El producto $articulo ya no está en la lista. Quítalo y vuelve a agregarlo.");
        $precio = $bonif ? ['publico' => 0.0, 'precio' => 0.0] : cotPrecio($cliente, $producto);
        if (!$bonif) $normales[$articulo] = true;
        $partidas[] = ['numero_articulo' => $articulo, 'descripcion' => $producto['descripcion'], 'codigo_barras' => $producto['codigo_barras'],
            'cantidad' => $cantidad, 'precio_publico' => $precio['publico'], 'precio' => $precio['precio'], 'bonificacion' => $bonif ? 1 : 0];
    }
    $t = cotTotales($partidas, $nombreCliente, $modalidad);
    if ($t['subtotal'] <= 0) throw new InvalidArgumentException('El total es $0.00. Agrega productos con precio antes de enviar.');

    // Archivos que arma el navegador (PDF; en pedidos también Excel).
    $archivo = function (string $k, string $mime) {
        $f = $_FILES[$k] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name']) || $f['size'] > 10 * 1024 * 1024) return null;
        $nombre = preg_replace('/[^\w .()-]+/u', '', (string)$f['name']) ?: $k;
        return ['contenido' => (string)file_get_contents($f['tmp_name']), 'nombre' => $nombre, 'mime' => $mime];
    };
    $adjuntos = [$archivo('pdf', 'application/pdf')];
    if ($tipo === 'pedido') $adjuntos[] = $archivo('excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    if (in_array(null, $adjuntos, true)) throw new InvalidArgumentException('No se recibieron los archivos del ' . ($tipo === 'pedido' ? 'pedido (PDF y Excel)' : 'la cotización (PDF)') . '.');

    $db->beginTransaction();
    $db->prepare('INSERT INTO cot_pedidos (usuario_id, tipo, modalidad, origen_id, cliente_id, cliente, correo, telefono, direccion, comentarios, piezas, subtotal, iva, descuento, total, creado_en)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$user['id'], $tipo, $modalidad, $origen, $cliente['id'], $nombreCliente, $form['correo'], $form['telefono'], $form['direccion'],
            $tipo === 'pedido' ? $form['comentarios'] : null, $t['piezas'], $t['subtotal'], $t['iva'], $t['descuento'], $t['total'], date('Y-m-d H:i:s')]);
    $pedidoId = (int)$db->lastInsertId();
    $insert = $db->prepare('INSERT INTO cot_pedido_partidas (pedido_id, numero_articulo, descripcion, codigo_barras, cantidad, precio_publico, precio, bonificacion) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ($partidas as $p) $insert->execute([$pedidoId, ...array_values($p)]);
    $db->commit();

    $rep = h($user['name']);
    if ($tipo === 'pedido') {
        $html = '<p>Estimado <strong>' . h(explode(' ', COT_COMPRAS['name'])[0]) . '</strong>,</p>'
            . '<p>Adjunto encontrarás el PDF y el archivo Excel con el detalle del pedido #' . $pedidoId . ' para <strong>' . h($nombreCliente) . '</strong>,'
            . ' generado por el representante médico <strong>' . $rep . '</strong>' . ($modalidad === 'mostrador' ? ' (venta mostrador)' : '')
            . ($origen ? ' a partir de su cotización #' . $origen : '') . '.</p>'
            . '<p>Total: <strong>$' . number_format($t['total'], 2) . '</strong> · ' . $t['piezas'] . ' piezas.</p>'
            . '<p>Favor de notificarle en privado cuando se levante el pedido.</p><p>Saludos.</p>';
        $enviado = intranet_correo([COT_COMPRAS], [['name' => $user['name'], 'email' => $user['email']]], 'Pedido #' . $pedidoId . ' - ' . $nombreCliente, $html,
            ['from_name' => 'Pedidos Fedele', 'reply_to' => $user['email'], 'bcc' => COT_COPIA_OCULTA, 'attachments' => $adjuntos]);
    } else {
        $html = '<p>Estimado(a) <strong>' . h($nombreCliente) . '</strong>,</p>'
            . '<p>Adjunto encontrarás tu cotización en PDF, elaborada por el representante médico <strong>' . $rep . '</strong>.</p>'
            . '<p>Saludos cordiales,<br><em>Equipo Fedele</em></p>';
        $enviado = intranet_correo([['name' => $nombreCliente, 'email' => $form['correo']]], [], 'Cotización de productos Fedele', $html,
            ['from_name' => 'Cotizaciones Fedele', 'reply_to' => $user['email'], 'attachments' => $adjuntos]);
    }
    if (!$enviado) {
        respond(['error' => 'Se guardó en tu historial (#' . $pedidoId . '), pero el correo no se pudo enviar. Intenta de nuevo en unos minutos o avisa al área de sistemas.'], 502);
    }
    $db->prepare('UPDATE cot_pedidos SET enviado = 1 WHERE id = ?')->execute([$pedidoId]);
    return ['ok' => true, 'id' => $pedidoId, 'message' => $tipo === 'pedido'
        ? 'Pedido #' . $pedidoId . ' enviado a Compras. Se envió una copia a tu correo.'
        : 'Cotización enviada correctamente a ' . $form['correo'] . '.'];
}
