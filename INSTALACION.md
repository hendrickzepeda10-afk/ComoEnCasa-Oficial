# Instalar y trasladar Como en Casa

La aplicación es un sitio PHP conectado a MySQL. Hay que ejecutarla mediante
Apache; no se debe abrir `index.php` directamente ni usar una dirección
`file://`.

## Requisitos del servidor

Se recomienda WampServer en Windows con Apache, PHP 8.2 o posterior y MySQL
8.0 o posterior. El código requiere PHP 7.4 como mínimo.

En la configuración de PHP utilizada por Apache, habilita estas extensiones:

- `pdo_mysql` para la conexión a MySQL.
- `mbstring` para validar y buscar texto UTF-8.
- `fileinfo` para validar documentos e imágenes subidos.
- `iconv` para normalizar texto importado desde hojas de cálculo.
- `zip` para importar y exportar archivos Excel `.xlsx`; `SimpleXML` para
  importar `.xlsx`. Sin ellas se puede importar `.csv`, pero no `.xlsx`.

En `php.ini`, configura al menos:

```ini
upload_max_filesize = 8M
post_max_size = 16M
```

Reinicia Apache después de cambiar `php.ini`. Los cuatro documentos del personal
pueden pesar hasta 1.5 MB cada uno.

PHP necesita permiso para escribir en `img/` (imágenes de platillos) y
`respaldos/` (copias de seguridad). Los documentos privados del personal se
guardan fuera del directorio público del sitio.

## Trasladar el sistema y sus datos a otra computadora

Haz estos pasos antes de dejar de usar la computadora actual:

1. En **Respaldo de Datos**, inicia sesión como administrador, crea un respaldo
   y descárgalo. Ese archivo `.sql` contiene los usuarios, pedidos, platillos,
   recetas e inventario actuales.
2. Copia la carpeta completa del proyecto a una memoria USB o disco externo.
   Conserva `css/`, `img/`, `database/` y los archivos PHP. Copia también la
   carpeta privada de documentos del personal, explicada más abajo. No es
   necesario copiar `respaldos/` si ya descargaste el respaldo más reciente.
3. En la computadora nueva, instala WampServer e inicia Apache y MySQL.
4. Coloca el proyecto dentro de `C:\wamp64\www\ComoEnCasa-Cambios` o en la
   carpeta `www` de la instalación local de WampServer.
5. En phpMyAdmin, crea una base vacía llamada `comoencasa_db` con cotejamiento
   `utf8mb4_unicode_ci`. Importa en ella el respaldo descargado en el paso 1.
6. Comprueba la configuración de [conexion.php](./conexion.php). Por defecto
   usa `localhost`, puerto `3306`, base `comoencasa_db`, usuario `root` y
   contraseña vacía. Si el nuevo MySQL tiene otros valores, define las
   variables de entorno `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER` y
   `DB_PASSWORD`, o actualiza los valores predeterminados del archivo.
7. Abre `http://localhost/ComoEnCasa-Cambios/` en la computadora nueva e inicia
   sesión con uno de los usuarios trasladados.

El respaldo descargado de la aplicación ya contiene la estructura actual de la
base de datos. Para este traslado no ejecutes las migraciones ni importes encima
de él `database/comoencasa_db.sql`: ese archivo solo contiene la estructura
vacía para una instalación nueva y no restaura los datos del negocio.

## Archivos privados del personal

Los documentos del personal no se incluyen en el respaldo SQL. Por defecto,
WampServer los guarda fuera de la carpeta web en
`C:\wamp64\documentos_personal_privados`. Copia esa carpeta de la computadora
anterior a la nueva y configura la variable de entorno `PERSONAL_DOCUMENTOS_DIR`
para apuntar a su ubicación, siempre fuera del directorio público del sitio.
La cuenta de Windows que ejecuta Apache debe poder leer y escribir allí.

Si no tienes documentos que trasladar, la aplicación creará esa carpeta al
subir los primeros. No guardes documentos privados dentro de `www`.

## Instalar desde cero (sin datos anteriores)

1. Copia el proyecto dentro de la carpeta web e inicia Apache y MySQL.
2. En phpMyAdmin crea una base `comoencasa_db` con cotejamiento
   `utf8mb4_unicode_ci`.
3. Importa `database/comoencasa_db.sql` en la base vacía.
4. Genera un hash para la contraseña inicial con PHP; reemplaza
   `ELIGE_UNA_CLAVE_SEGURA` por una clave propia y no compartas el resultado:

   ```sh
   php -r "echo password_hash('ELIGE_UNA_CLAVE_SEGURA', PASSWORD_DEFAULT), PHP_EOL;"
   ```

5. En phpMyAdmin, crea la cuenta administradora y pega el hash generado en
   lugar de `PEGA_AQUI_EL_HASH`:

   ```sql
   INSERT INTO usuarios (usuario, password, rol, estado)
   VALUES ('admin', 'PEGA_AQUI_EL_HASH', 'admin', 1);
   ```

6. Revisa las credenciales de [conexion.php](./conexion.php) y abre
   `http://localhost/ComoEnCasa-Cambios/`.

El SQL de instalación contiene solo la estructura: no incluye datos de ejemplo,
usuarios ni contraseñas.

## Acceso desde otras computadoras de la misma red

La computadora donde corre WampServer será el servidor. En ella, ejecuta
`ipconfig` y busca su dirección IPv4; en los otros dispositivos abre
`http://DIRECCION-IP/ComoEnCasa-Cambios/` (por ejemplo,
`http://192.168.1.50/ComoEnCasa-Cambios/`). Autoriza Apache en el firewall de
Windows para redes privadas. Mantén MySQL accesible solo desde el servidor y no
expongas Apache ni MySQL directamente a Internet.

Cada dispositivo inicia sesión por separado y utiliza la misma base de datos
del servidor. Usa una dirección IP fija o una reserva DHCP para que el enlace
no cambie cuando se reinicie el router.

En el mismo navegador, cada pestaña conserva su propia sesión: cerrar sesión
en una pestaña no cierra las demás. Para este comportamiento, el navegador
debe permitir JavaScript y el almacenamiento de sesión y local del sitio.

## Migrar una base existente sin cambiar de computadora

Antes de actualizar una base, crea un respaldo. Aplica solo las migraciones que
esa base todavía no tenga; no ejecutes dos veces un `ALTER TABLE` ni importes
el SQL de instalación encima de datos que quieras conservar.

Las migraciones disponibles son:

- `database/migracion_bebidas_alcoholicas.sql`
- `database/migracion_pedidos_en_linea.sql`
- `database/migracion_personal_turnos.sql`
- `database/migracion_eliminar_rol_cliente.sql`
- `database/migracion_expedientes_personal.sql`
- `database/migracion_insumos_conversion.sql`
- `database/migracion_resenas.sql`

Las migraciones de personal e insumos son necesarias únicamente en bases
anteriores que aún no tengan esas columnas. `migracion_expedientes_personal.sql`
agrega datos personales y referencias a documentos. Completa la información
requerida del personal desde **Gestión de Usuarios** y asigna los turnos que
falten; las migraciones no crean esos datos ni documentos.

`migracion_insumos_conversion.sql` agrega las unidades de compra y el factor de
conversión, y configura las tablas de pedidos de cocina para usar InnoDB. Las
instalaciones nuevas ya tienen esos cambios.

## Comprobación después de instalar

1. En WampServer confirma que Apache y MySQL estén activos.
2. Abre la página de inicio e inicia sesión.
3. Comprueba **Pedir Ya**, **Caja**, **Órdenes**, **Insumos** y **Respaldo de
   Datos** con una cuenta que tenga permiso.
4. Crea y descarga un respaldo de prueba; no lo compartas: contiene datos del
   negocio y de sus usuarios.
5. Si no cargan iconos, comprueba el acceso a Internet: Font Awesome se carga
   desde un CDN externo.
