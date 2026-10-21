<?php
include 'auth.php';
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/funciones.php';

$error_resena = '';
$nombre_resena = '';
$comentario_resena = '';
$calificacion_resena = 0;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['accion'] ?? '') === 'crear_resena') {
    $nombre_resena = is_string($_POST['nombre'] ?? null) ? trim($_POST['nombre']) : '';
    $comentario_resena = is_string($_POST['comentario'] ?? null) ? trim($_POST['comentario']) : '';
    $calificacion_resena = filter_var($_POST['calificacion'] ?? null, FILTER_VALIDATE_INT);

    if (!csrf_peticion_valida()) {
        $error_resena = 'El formulario expiró. Actualiza la página e inténtalo de nuevo.';
    } elseif (
        $nombre_resena !== ''
        && (mb_strlen($nombre_resena, 'UTF-8') > 80 || preg_match('/[\x00-\x1F\x7F]/u', $nombre_resena))
    ) {
        $error_resena = 'El nombre no puede superar 80 caracteres.';
    } elseif (mb_strlen($comentario_resena, 'UTF-8') < 5 || mb_strlen($comentario_resena, 'UTF-8') > 1000) {
        $error_resena = 'El comentario debe tener entre 5 y 1000 caracteres.';
    } elseif ($calificacion_resena === false || $calificacion_resena < 1 || $calificacion_resena > 5) {
        $error_resena = 'Selecciona una calificación de 1 a 5 estrellas.';
    } elseif (trim(is_string($_POST['sitio_web'] ?? null) ? $_POST['sitio_web'] : '') !== '') {
        $error_resena = 'No se pudo enviar la reseña. Inténtalo de nuevo.';
    } else {
        try {
            $stmt_resena = $pdo->prepare(
                'INSERT INTO resenas (nombre, comentario, calificacion) VALUES (:nombre, :comentario, :calificacion)'
            );
            $stmt_resena->execute([
                'nombre' => $nombre_resena !== '' ? $nombre_resena : null,
                'comentario' => $comentario_resena,
                'calificacion' => $calificacion_resena,
            ]);
            header('Location: resenas.php?resena=publicada');
            exit();
        } catch (PDOException $e) {
            error_log('No se pudo guardar la reseña: ' . $e->getMessage());
            $error_resena = 'No pudimos guardar tu reseña en este momento. Inténtalo más tarde.';
        }
    }
}

$resena_publicada = ($_GET['resena'] ?? '') === 'publicada';
$resenas = [];
$error_resenas = false;
try {
    $resenas = $pdo->query(
        'SELECT nombre, comentario, calificacion, creado_en FROM resenas ORDER BY creado_en DESC, id DESC LIMIT 6'
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('No se pudieron cargar las reseñas: ' . $e->getMessage());
    $error_resenas = true;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Comparte tu experiencia en Como en Casa y conoce las opiniones de otros clientes.">
    <title>Reseñas - Como en Casa</title>
    <link rel="stylesheet" href="css/estilos.css?v=20261019-tipografia">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="customer-home">
    <?php include 'header.php'; ?>

    <main class="customer-home-main">
        <section class="customer-reviews customer-review-page" aria-labelledby="reviewsHeading">
            <div class="customer-section-heading">
                <div>
                    <span class="customer-eyebrow"><i class="fa-solid fa-star" aria-hidden="true"></i> Tu opinión cuenta</span>
                    <h1 id="reviewsHeading">Comparte tu reseña</h1>
                    <p>Cuéntanos cómo fue tu experiencia y conoce las opiniones de otros clientes.</p>
                </div>
            </div>

            <?php if ($resena_publicada): ?>
                <p class="customer-review-message customer-review-message--success" role="status">¡Gracias! Tu reseña se publicó correctamente.</p>
            <?php endif; ?>
            <?php if ($error_resena !== ''): ?>
                <p class="customer-review-message customer-review-message--error" role="alert"><?php echo htmlspecialchars($error_resena, ENT_QUOTES, 'UTF-8'); ?></p>
            <?php endif; ?>

            <div class="customer-review-panel">
                <div class="customer-review-panel-form">
                    <h2>Escribe tu reseña</h2>
                    <p class="customer-review-panel-intro">Tu opinión nos ayuda a brindarte una mejor experiencia.</p>
                    <form class="customer-review-form" method="POST" action="resenas.php">
                        <?php csrf_campo(); ?>
                        <input type="hidden" name="accion" value="crear_resena">
                        <div class="customer-review-honeypot" aria-hidden="true">
                            <label for="resenaSitioWeb">Deja este campo vacío</label>
                            <input type="text" id="resenaSitioWeb" name="sitio_web" tabindex="-1" autocomplete="off">
                        </div>

                        <div class="customer-review-field">
                            <label class="customer-review-label" for="resenaNombre">Tu nombre <span>(opcional)</span></label>
                            <input
                                class="customer-review-input"
                                type="text"
                                id="resenaNombre"
                                name="nombre"
                                maxlength="80"
                                autocomplete="name"
                                value="<?php echo htmlspecialchars($nombre_resena, ENT_QUOTES, 'UTF-8'); ?>"
                                placeholder="¿Cómo te llamas?"
                            >
                        </div>

                        <fieldset class="customer-review-rating">
                            <legend>¿Cómo calificas tu experiencia? <span aria-hidden="true">*</span></legend>
                            <div class="customer-review-stars" role="radiogroup" aria-label="Calificación de 1 a 5 estrellas">
                                <?php for ($estrella = 5; $estrella >= 1; $estrella--): ?>
                                    <input
                                        type="radio"
                                        id="resenaEstrella<?php echo $estrella; ?>"
                                        name="calificacion"
                                        value="<?php echo $estrella; ?>"
                                        required
                                        <?php echo (int) $calificacion_resena === $estrella ? 'checked' : ''; ?>
                                    >
                                    <label for="resenaEstrella<?php echo $estrella; ?>" aria-label="<?php echo $estrella; ?> <?php echo $estrella === 1 ? 'estrella' : 'estrellas'; ?>">★</label>
                                <?php endfor; ?>
                            </div>
                            <span class="customer-review-hint">Elige de 1 (no me gustó) a 5 (¡excelente!).</span>
                        </fieldset>

                        <div class="customer-review-field">
                            <label class="customer-review-label" for="resenaComentario">Tu comentario <span aria-hidden="true">*</span></label>
                            <textarea
                                class="customer-review-input customer-review-comment"
                                id="resenaComentario"
                                name="comentario"
                                rows="5"
                                minlength="5"
                                maxlength="1000"
                                required
                                placeholder="¿Qué te gustó de la comida o la atención?"
                            ><?php echo htmlspecialchars($comentario_resena, ENT_QUOTES, 'UTF-8'); ?></textarea>
                            <span class="customer-review-hint">De 5 a 1000 caracteres.</span>
                        </div>

                        <button class="customer-review-submit" type="submit">
                            Publicar reseña <i class="fa-solid fa-paper-plane" aria-hidden="true"></i>
                        </button>
                    </form>
                </div>

                <div class="customer-review-list" aria-live="polite">
                    <h2>Opiniones recientes</h2>
                    <?php if ($error_resenas): ?>
                        <p class="customer-review-message customer-review-message--error" role="status">No pudimos cargar las reseñas anteriores.</p>
                    <?php elseif ($resenas): ?>
                        <div class="customer-review-grid">
                            <?php foreach ($resenas as $resena): ?>
                                <article class="customer-review-card">
                                    <div class="customer-review-card-stars" aria-label="<?php echo (int) $resena['calificacion']; ?> de 5 estrellas">
                                        <?php for ($estrella = 1; $estrella <= 5; $estrella++): ?>
                                            <span aria-hidden="true"><?php echo $estrella <= (int) $resena['calificacion'] ? '★' : '☆'; ?></span>
                                        <?php endfor; ?>
                                    </div>
                                    <p><?php echo nl2br(htmlspecialchars($resena['comentario'], ENT_QUOTES, 'UTF-8')); ?></p>
                                    <small>
                                        <?php echo htmlspecialchars($resena['nombre'] ?: 'Cliente', ENT_QUOTES, 'UTF-8'); ?>
                                        · <?php echo date('d/m/Y', strtotime($resena['creado_en'])); ?>
                                    </small>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="customer-review-empty">Aún no hay reseñas. ¡Sé la primera persona en compartir tu experiencia!</p>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    </main>
</body>
</html>
