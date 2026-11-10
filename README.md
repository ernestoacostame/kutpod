# KutPod

<p align="center">
  <strong>🇬🇧 <a href="#english">English</a></strong> · <strong>🇪🇸 <a href="#español">Español</a></strong>
</p>

<p align="center">
  <em>The lightweight, self-hosted podcast platform with native Fediverse (ActivityPub) integration, Podcasting 2.0 namespaces, and pure PHP & SQLite architecture.</em>
</p>

---

<a name="english"></a>
## 🇬🇧 English

**KutPod** is an independent, self-hosted podcast web hosting and management platform built in **pure PHP and SQLite** with zero heavyweight frameworks, Node.js dependencies, or database servers required. It acts as the core server (backend, API, and frontend) of the **KutStudio** suite.

### Key Features

* **Self-Contained & Ultra-Fast**: Runs on any standard Linux web server with PHP 8.1+ and SQLite. No Composer dependencies, no heavy database services (MySQL/PostgreSQL), and minimal memory footprint.
* **Native Fediverse Integration (ActivityPub)**: KutPod functions as a fully federated instance. Fediverse users (Mastodon, Pleroma, Misskey, etc.) can discover, follow, and interact with your podcasts directly using handles like `@mypodcast@yourdomain.com`.
* **Podcasting 2.0 Compliant**: Full support for Podcasting 2.0 tags including visual chapters, episode-specific art, transcript attachments, value-for-value elements, and location tags.
* **Native OP3 Analytics**: Built-in privacy-respecting download and audience analytics via the Open Podcast Prefix Project (OP3) without relying on invasive third-party ad networks.
* **Modern Public Frontend & Continuous Player**: Elegant public show directories, episode pages, and a persistent audio player that continues playing smoothly as visitors browse your site.
* **Granular Permissions & Multi-Show Hosting**: Host unlimited podcast shows from a single installation with multi-user role management (Superadmin, Owner, Editor, Author).
* **RESTful Client API**: High-performance API designed for seamless integration with desktop and mobile clients: **KutEditor**, **KutFast**, and **KutFast_Linux**.
* **Automated Web Installer & Updater**: Web-based setup wizard and one-click in-app updater that upgrades files safely from GitHub releases.

### Project Structure

* `/api/`: REST endpoints consumed by desktop clients and mobile tools.
* `/cli/`: CLI workers and background daemons (ActivityPub delivery worker, backup engine, RSS importers).
* `/includes/`: Core business logic, SQLite database layer, security filters, and template helpers.
* `/pages/`: Secure administration dashboard and show management interface.
* `/public/`: Public-facing podcast sites, theme assets, and audio player scripts.
* `/storage/`: Encrypted SQLite database (`kutpod.db`) and secure keys.
* `/media/`: Local storage for episode audio files, covers, artwork, and banners.

### Installation & Deployment

1. **Clone or Extract**: Place the KutPod files into your web server directory (e.g. `/var/www/html/kutpod`).
2. **Set File Permissions**:
   ```bash
   sudo chown -R www-data:www-data /var/www/html/kutpod
   sudo chmod -R 755 /var/www/html/kutpod
   ```
3. **Web Wizard**: Navigate to `https://yourdomain.com/install.php` in your browser and follow the step-by-step setup wizard to create the administrator account.
4. **Cron Worker**: Add the Fediverse background delivery worker to your crontab:
   ```bash
   * * * * * php /var/www/html/kutpod/cli/worker.php >/dev/null 2>&1
   ```

> [!IMPORTANT]
> Always review `security_guide.md` and ensure direct HTTP access to the `/storage` directory is blocked in your Nginx or Apache configuration.

### The KutStudio Suite

KutPod connects natively with:
* **KutEditor**: Desktop multitrack DAW, chapter editor, and audio production environment (Qt 6 / C++).
* **KutFast**: Agile web publisher for recording and instant episode creation.
* **KutFast_Linux**: Native Linux desktop companion for rapid episode dispatching.

---

<a name="español"></a>
## 🇪🇸 Español

**KutPod** es una plataforma de alojamiento web y gestión de podcasts independiente y auto-alojada, construida en **PHP puro y SQLite**, sin dependencias de Node.js, Composer ni bases de datos pesadas. Sirve como el núcleo de servidor (backend, API y frontend) de la suite **KutStudio**.

### Características Principales

* **Independiente y Ultra-Ligero**: Funciona en cualquier servidor web estándar con PHP 8.1+ y SQLite. Sin necesidad de servicios pesados (MySQL/PostgreSQL) y con un consumo de recursos mínimo.
* **Integración Nativa con el Fediverso (ActivityPub)**: KutPod actúa como una instancia federada completa. Los usuarios de Mastodon, Pleroma, Misskey y el resto del Fediverso pueden buscar, seguir e interactuar con tus podcasts directamente (ej. `@mipodcast@tudominio.com`).
* **Soporte Podcasting 2.0**: Compatibilidad total con especificaciones de Podcasting 2.0: capítulos visuales, arte individual por episodio, transcripciones y enlaces de valor.
* **Estadísticas Nativas con OP3**: Métricas de audiencia y descargas transparentes y respetuosas con la privacidad mediante la integración con Open Podcast Prefix Project.
* **Frontend Moderno y Reproductor Global Continuo**: Portal público personalizable para cada podcast con un reproductor que no interrumpe la reproducción mientras se navega por el sitio.
* **Gestión Multiusuario y Múltiples Podcasts**: Aloja múltiples programas en una misma instalación con roles diferenciados (Superadministrador, Dueño, Editor, Autor).
* **API RESTful para Clientes**: Interfaz de API de alto rendimiento lista para conectar con los clientes de escritorio **KutEditor**, **KutFast** y **KutFast_Linux**.
* **Instalador y Actualizador Web**: Asistente de configuración paso a paso y sistema de actualización automática con 1 clic desde GitHub Releases.

### Estructura del Proyecto

* `/api/`: Endpoints REST consumidos por los clientes de la suite KutStudio.
* `/cli/`: Herramientas y demonios de línea de comandos (Worker de Fediverso, copias de seguridad, importadores).
* `/includes/`: Lógica central de la aplicación, capa de datos SQLite y helpers de interfaz.
* `/pages/`: Panel de administración (Dashboard).
* `/public/`: Frontend de los sitios públicos de los podcasts y portal del reproductor de episodios.
* `/storage/`: Base de datos SQLite (`kutpod.db`) y archivos de claves criptográficas.
* `/media/`: Almacenamiento multimedia para audios de episodios, portadas y banners.

### Instalación y Despliegue

1. **Subir Archivos**: Copia los archivos de KutPod en el directorio raíz de tu servidor web (ej. `/var/www/html/kutpod`).
2. **Configurar Permisos**:
   ```bash
   sudo chown -R www-data:www-data /var/www/html/kutpod
   sudo chmod -R 755 /var/www/html/kutpod
   ```
3. **Asistente Web**: Accede a `https://tudominio.com/install.php` y completa el formulario de inicialización para crear el usuario administrador.
4. **Tarea Programada (Cron)**: Configura la ejecución periódica del worker para la entrega federada en tu crontab:
   ```bash
   * * * * * php /var/www/html/kutpod/cli/worker.php >/dev/null 2>&1
   ```

> [!IMPORTANT]
> Consulta `security_guide.md` y asegúrate de restringir el acceso web directo al directorio `/storage` en tu servidor web (Nginx o Apache).

### Suite KutStudio

KutPod se integra de forma transparente con:
* **KutEditor**: Editor de audio multipista, gestor de capítulos y generador de episodios (Qt 6 / C++).
* **KutFast**: Publicador web ligero para crear episodios al instante.
* **KutFast_Linux**: Cliente nativo para escritorio Linux enfocado en publicación ágil.

---

## 💖 Donaciones / Donations

Si los proyectos de **KutStudio** te son de utilidad en tu flujo de trabajo como creador de contenidos, considera apoyar su desarrollo. ¡Cada aporte ayuda a mantener el proyecto vivo y en constante mejora!

If **KutStudio** projects are helpful in your creative workflow, consider supporting development with a donation. Every contribution helps keep the project alive and constantly improving!

<p align="center">
  <a href="https://paypal.me/elav">
    <img src="https://img.shields.io/badge/PayPal-Donar%20%2F%20Donate-00457C?style=for-the-badge&logo=paypal&logoColor=white" alt="PayPal">
  </a>
  &nbsp;&nbsp;
  <a href="https://www.buymeacoffee.com/ernestoacostame">
    <img src="https://img.shields.io/badge/Buy%20Me%20a%20Coffee-Invítame%20un%20café-FFDD00?style=for-the-badge&logo=buy-me-a-coffee&logoColor=black" alt="Buy Me a Coffee">
  </a>
  &nbsp;&nbsp;
  <a href="https://ko-fi.com/ernestoacostame">
    <img src="https://img.shields.io/badge/Ko--fi-Apóyame-FF5E5B?style=for-the-badge&logo=ko-fi&logoColor=white" alt="Ko-fi">
  </a>
</p>
