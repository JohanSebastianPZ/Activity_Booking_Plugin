<?php
if (!defined('ABSPATH')) {
	exit;
}

class CollaboratorManager
{

	public function __construct()
	{
		add_action('init', array($this, 'init_hooks'));
	}

	public function init_hooks()
	{
		// AJAX para colaboradores
		add_action('wp_ajax_propose_booking_dates', array($this, 'propose_booking_dates'));

		// AJAX para clientes
		add_action('wp_ajax_accept_booking_date', array($this, 'accept_booking_date'));
		//add_action('wp_ajax_nopriv_accept_booking_date', array($this, 'accept_booking_date'));

		// NUEVO: AJAX para rechazar fechas
		add_action('wp_ajax_reject_booking_dates', array($this, 'reject_booking_dates'));
		//add_action('wp_ajax_nopriv_reject_booking_dates', array($this, 'reject_booking_dates'));

		// Shortcodes
		add_shortcode('collaborator_dashboard', array($this, 'collaborator_dashboard_shortcode'));
		add_shortcode('client_bookings', array($this, 'client_bookings_shortcode'));

		// NUEVO: AJAX para generar la factura (solo para usuarios logueados)
		add_action('wp_ajax_generate_invoice', array($this, 'handle_invoice_download'));
	}

	public function reject_booking_dates()
	{
		check_ajax_referer('booking_nonce', 'nonce');

		$booking_id = intval($_POST['booking_id']);
		$suggested_start_date = sanitize_text_field($_POST['suggested_start_date']);
		$suggested_end_date = sanitize_text_field($_POST['suggested_end_date']);
		$rejection_reason = sanitize_textarea_field($_POST['rejection_reason']);

		if (empty($booking_id)) {
			wp_send_json_error('ID de reserva requerido');
		}

		$db_manager = new BookingDatabase();
		$booking = $db_manager->get_booking_by_id($booking_id);

		if (!$booking) {
			wp_send_json_error('Reserva no encontrada');
		}

		// Verificar que el usuario actual es el propietario de la reserva
		if (!is_user_logged_in() || (get_current_user_id() != $booking->customer_id && !current_user_can('administrator'))) {
			wp_send_json_error('No tienes permisos para esta acción');
		}

		$date_range_suggestion = null;
		if (!empty($suggested_start_date) && !empty($suggested_end_date)) {
			$date_range_suggestion = array(
				'start_date' => $suggested_start_date,
				'end_date' => $suggested_end_date,
				'reason' => $rejection_reason
			);
		}

		$result = $db_manager->reject_proposed_dates($booking_id, $date_range_suggestion);

		if ($result) {
			// Notificar al colaborador sobre el rechazo
			$this->notify_collaborator_dates_rejected($booking, $date_range_suggestion);

			wp_send_json_success('Fechas rechazadas. El colaborador propondrá nuevas fechas pronto.');
		} else {
			wp_send_json_error('Error al procesar el rechazo');
		}
	}

	private function notify_collaborator_dates_rejected($booking, $date_range_suggestion)
	{
		$collaborator = get_user_by('ID', $booking->collaborator_id);
		if (!$collaborator || !is_email($collaborator->user_email)) {
			return;
		}

		$experience_title = get_the_title($booking->product_id);
		$customer_name = esc_html($booking->customer_name);
		$start = $end = $reason = '';

		if ($date_range_suggestion) {
			if (!empty($date_range_suggestion['start_date'])) {
				$start = date_i18n('d/m/Y', strtotime($date_range_suggestion['start_date']));
			}
			if (!empty($date_range_suggestion['end_date'])) {
				$end = date_i18n('d/m/Y', strtotime($date_range_suggestion['end_date']));
			}
			if (!empty($date_range_suggestion['reason'])) {
				$reason = esc_html($date_range_suggestion['reason']);
			}
		}

		ob_start();
		?>
		<!DOCTYPE html>
		<html>

			<head>
				<meta charset="utf-8" />
				<title>Fechas Rechazadas</title>
				<style>
					* {
						color: black;
						font-family: "Nunito";
					}

					table {
						width: 100%;
						max-width: 600px;
						margin: 0 auto;
						border-collapse: collapse;
					}

					th,
					td {
						padding: 10px;
						text-align: center;
					}

					h1 {
						font-size: 22px;
						margin-bottom: 20px;
						font-weight: 700;
					}

					p {
						font-size: 16px;
						margin: 10px 0;
					}

					a {
						color: #f07e13;
						text-decoration: underline;
					}

					@media screen and (max-width: 600px) {
						table {
							width: 100%;
						}
					}

					#template_header_image {
						width: 100%;
						display: inline-block;
						text-align: center;
						margin: 20px 0 25px 0;
					}

					#template_header_image img {
						height: 50px;
					}

					#template_header_nav {
						background-color: #71b849;
						color: white;
						border-top-left-radius: 25px;
						border-bottom-right-radius: 25px;
						margin-bottom: 15px;
					}

					#template_header_nav td {
						height: 42px;
						vertical-align: middle;
					}

					#template_header_nav a {
						color: white;
						text-decoration: none;
						padding: 20px 5px;
						font-family: "Nunito";
						font-size: 18px;
						font-weight: 600;
					}

					h1 {
						font-size: 22px;
						margin-bottom: 35px;
						font-weight: 700;
					}

					p {
						font-size: 16px;
						margin: 0 0 5px 0;
					}

					.header {
						margin-bottom: 10px;
					}

					.header p,
					.header span {
						text-align: left;
					}

					.header p {
						margin: 0 0 10px 0;
					}

					.header span {
						font-weight: 700;
						margin: 0 0 8px 0;
						display: block;
					}

					#template_table {
						font-weight: 700;
						font-size: 18px;
						margin: 5px auto 25px;
					}

					#template_grettings {
						margin-top: 10px;
					}

					#template_grettings .quote p {
						font-style: italic;
					}

					#template_grettings .quote span {
						font-weight: 700;
						margin-top: 15px;
						display: block;
					}

					#template_grettings .grettings {
						margin: 15px 0 10px 0;
						display: block;
						text-align: left;
					}

					#template_grettings .grettings p {
						text-align: left;
					}

					#template_grettings .grettings span {
						font-weight: 700;
					}

					.footer {
						margin-top: 10px;
					}

					.footer .logo_footer img {
						height: 40px;
						margin: 10px 0 20px;
					}

					.footer td {
						padding: 0;
					}

					.footer .logo_footer {
						border-bottom: 5px solid #71b849;
					}

					.footer .aviso-privacidad,
					.footer .aviso-privacidad p {
						background-color: white;
						color: black;
						text-align: left;
						font-size: 10px;
					}

					.footer .aviso-privacidad {
						margin-top: 25px;
					}

					.body.table {
						width: fit-content;
						border: 1px solid #ccc;
						margin: 10px auto;
						max-width: 100%;
					}

					.body.table tr td:first-child {
						font-weight: 700;
						border-right: 1px solid #ccc;
					}

					.body.body.table tr td {
						text-align: left;
						padding-right: 30px;
					}

					.body.body.table tr {
						border: 1px solid #ccc;
					}

					.body.table tr td:last-child {
						font-weight: 500;
						border-right: 1px solid #ccc;
					}

					@media screen and (max-width: 600px) {
						table {
							width: 100%;
						}

						#template_header_nav td {
							display: block;
							height: auto;
						}
					}
				</style>
			</head>

			<body>
				<div id="template_header_image">
					<img src="https://ms.regalexia.com/wp-content/uploads/2023/04/logo.png" alt="Regalexia" />
				</div>

				<table id="template_header_nav">
					<tr>
						<td><a href="https://regalexia.com/experiencias/">+ Experiencias</a></td>
						<td><a href="https://regalexia.com/amigos-regalexia/">+ Amigos Regalexia</a></td>
						<td><a href="https://regalexia.com/consejos-para-padres/">+ Blog</a></td>
					</tr>
				</table>

				<table class="header">
					<tr>
						<td>
							<h1>Fechas rechazadas - <?= esc_html($experience_title); ?></h1>
							<p>Hola <?= esc_html($collaborator->display_name); ?>,</p>
							<p>El cliente <strong><?= $customer_name; ?></strong> ha rechazado las fechas que propusiste para la experiencia <strong><?= esc_html($experience_title); ?></strong>.</p>
						</td>
					</tr>
				</table>

				<?php if ($start || $end || $reason): ?>
					<table class="body table">
						<?php if ($start): ?>
							<tr>
								<td>Fecha sugerida (desde)</td>
								<td><?= $start; ?></td>
							</tr>
						<?php endif; ?>
						<?php if ($end): ?>
							<tr>
								<td>Fecha sugerida (hasta)</td>
								<td><?= $end; ?></td>
							</tr>
						<?php endif; ?>
						<?php if ($reason): ?>
							<tr>
								<td>Comentarios del cliente</td>
								<td><?= $reason; ?></td>
							</tr>
						<?php endif; ?>
					</table>
				<?php endif; ?>

				<table class="body">
					<tr>
						<td>
							Puedes proponer nuevas fechas desde tu <a href="https://regalexia.com/administrar-reservas/">panel de colaborador</a>.
						</td>
					</tr>
				</table>

				<table class="footer">
					<tr>
						<td class="logo_footer">
							<img src="https://ms.regalexia.com/wp-content/uploads/2023/04/cropped-favico.png" />
						</td>
					</tr>
					<tr>
						<td>
							<p class="aviso-privacidad">
								<b>PROTECCIÓN DE DATOS:</b>
								De conformidad con lo dispuesto en el Reglamento (UE) 2016/679, de
								27 de abril (GDPR), y la Ley Orgánica 3/2018, de 5 de diciembre
								(LOPDGDD), le informamos de que los datos personales y la dirección
								de correo electrónico del interesado, se tratarán bajo la
								responsabilidad de KIDSON, S.L. por un interés legítimo y para el
								envío de comunicaciones sobre nuestros productos y servicios, y se
								conservarán mientras ninguna de las partes se oponga a ello. Los
								datos no se comunicarán a terceros, salvo obligación legal. Le
								informamos de que puede ejercer los derechos de acceso,
								rectificación, portabilidad y supresión de sus datos y los de
								limitación y oposición a su tratamiento dirigiéndose a Aribau 168 1º1ª, 08036 Barcelona (Barcelona). E-mail:
								<a href="mailto:hola@regalexia.com">hola@regalexia.com</a>. Si
								considera que el tratamiento no se ajusta a la normativa vigente,
								podrá presentar una reclamación ante la autoridad de control en
								<a href="https://www.aepd.es/es">www.aepd.es</a>.
							</p>
						</td>
					</tr>
					<tr>
						<td>
							<p class="aviso-privacidad">
								<b>PUBLICIDAD:</b>
								En cumplimiento de lo previsto en el artículo 21 de la Ley 34/2002
								de Servicios de la Sociedad de la Información y Comercio Electrónico
								(LSSICE), si usted no desea recibir más información sobre nuestros
								productos y/o servicios, puede darse de baja enviando un correo
								electrónico a
								<a href="mailto:hola@regalexia.com">hola@regalexia.com</a>,
								indicando en el Asunto «BAJA» o «NO ENVIAR».
							</p>
						</td>
					</tr>
				</table>
			</body>

		</html>
		<?php

		$message = ob_get_clean();
		$subject = 'Fechas rechazadas - ' . $experience_title;
		$headers = ['Content-Type: text/html; charset=UTF-8'];

		wp_mail($collaborator->user_email, $subject, $message, $headers);
	}


	public function create_collaborator_role()
	{
		if (!get_role('activity_collaborator')) {
			add_role('activity_collaborator', 'Colaborador de Actividades', array(
				'read' => true,
				'manage_activity_bookings' => true,
			));
		}
	}

	public function propose_booking_dates()
	{
		error_log('AJAX propose_booking_dates iniciado');

		check_ajax_referer('booking_nonce', 'nonce');
		error_log('Nonce validado');

		if (!current_user_can('manage_activity_bookings')) {
			error_log('Permiso denegado');
			wp_send_json_error('No tienes permisos');
		}

		$booking_id = intval($_POST['booking_id']);
		$dates = isset($_POST['dates']) ? $_POST['dates'] : [];
		error_log('Booking ID: ' . $booking_id);
		error_log('Fechas recibidas: ' . print_r($dates, true));

		$dates = array_map('sanitize_text_field', $dates);

		if (empty($booking_id) || empty($dates) || count($dates) > 3) {
			error_log('Datos inválidos');
			wp_send_json_error('Datos inválidos');
		}

		$db_manager = new BookingDatabase();

		$booking = $db_manager->get_booking_by_id($booking_id);
		if (!$booking) {
			error_log('Reserva no encontrada');
			wp_send_json_error('Reserva no encontrada');
		}

		if ($booking->status === 'dates_rejected') {
			error_log('Reserva con fechas rechazadas, limpiando fechas previas');
			$db_manager->clear_previous_proposed_dates($booking_id);
		}

		$db_manager->add_proposed_dates($booking_id, $dates);
		$db_manager->update_booking_status($booking_id, 'dates_proposed');

		$this->send_dates_email($booking_id, $dates);

		error_log('Proceso finalizado con éxito');
		wp_send_json_success('Fechas enviadas al cliente');
	}


	public function accept_booking_date()
	{
		check_ajax_referer('booking_nonce', 'nonce');

		$booking_id = intval($_POST['booking_id']);
		$date_id = intval($_POST['date_id']);

		if (empty($booking_id) || empty($date_id)) {
			wp_send_json_error('Datos incompletos');
		}

		$db_manager = new BookingDatabase();
		$db_manager->accept_date($booking_id, $date_id);

		wp_send_json_success('Fecha confirmada');
	}

	public function collaborator_dashboard_shortcode()
	{
		if (!is_user_logged_in() || !current_user_can('manage_activity_bookings')) {
			return '<p>Acceso denegado. Debes ser un colaborador para ver esta página.</p>';
		}

		ob_start();
		$this->render_collaborator_dashboard();
		return ob_get_clean();
	}

	public function client_bookings_shortcode()
	{
		if (!is_user_logged_in()) {
			return '<p>Debes iniciar sesión para ver tus reservas.</p>';
		}

		ob_start();
		$this->render_client_bookings();
		return ob_get_clean();
	}

	// Esta funcion es la que permite hacer la creacion del documento PDF
	public function handle_invoice_download()
	{

		// 1. Verificación de Seguridad y Datos (ID de la ORDEN de WooCommerce)
		if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'booking_nonce')) {
			wp_die('Chequeo de seguridad fallido.');
		}

		// Este $booking_id es el ID de la ORDEN de WooCommerce (ej: 108)
		$order_id = isset($_GET['booking_id']) ? intval($_GET['booking_id']) : 0;
		$user_id = get_current_user_id();

		if (!$order_id || !$user_id) {
			// Usamos $order_id para claridad en el debug
			wp_die('Error: ID de orden o ID de usuario faltante.');
		}

		// 2. Obtener los datos de la reserva
		$db_manager = new BookingDatabase();

		// Buscamos la reserva por el campo ORDER_ID de WooCommerce, NO por el ID de fila.
		$booking_obj = $db_manager->get_booking_by_order_id_single($order_id);


		// 3. Verificar Permisos y Existencia
		if (!$booking_obj || $booking_obj->collaborator_id != $user_id && !current_user_can('administrator')) {
			wp_die('Acceso denegado o reserva no encontrada.');
		}

		// 4. Cargar la clase Generadora de Facturas
		$generator_path = plugin_dir_path(__FILE__) . 'class-invoice-generator.php';
		if (!file_exists($generator_path)) {
			wp_die('Error: Archivo generador de factura no encontrado.');
		} else {
			require_once $generator_path;
		}


		// 5. Instanciar y Generar el PDF
		$generator = new Booking_invoice_generator();
		$generator->generate_invoice($user_id, $booking_obj);

		//wp_die();
	}

	private function render_collaborator_dashboard()
	{
		$db_manager = new BookingDatabase();
		$db_manager->sync_with_woocommerce_orders();

		$bookings = array_filter(
			$db_manager->get_collaborator_bookings(get_current_user_id()),
			function ($b) {
				return $b->status !== '0.000000' && !empty($b->status);
			}
		);

		wp_enqueue_script('collaborator-dashboard', plugin_dir_url(dirname(__FILE__)) . 'assets/collaborator-dashboard.js', array('jquery'), '1.0', true);
		wp_enqueue_style(
			'collaborator-dashboard-css',
			plugin_dir_url(dirname(__FILE__)) . 'assets/collaborator-dashboard.css'
		);

		wp_localize_script('collaborator-dashboard', 'collaborator_ajax', array(
			'ajax_url' => admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce('booking_nonce')
		));
		?>

		<div id="collaborator-dashboard">
			<div class="dashboard-header">
				<h2><i class="icon-calendar"></i> Panel de Colaborador</h2>
				<div class="dashboard-stats">
					<div class="stat-card">
						<span class="stat-number"><?php echo count(array_filter($bookings, function ($b) {
							return $b->status === 'pending_dates'; })); ?></span>
						<span class="stat-label">Pendientes</span>
					</div>
					<div class="stat-card">
						<span class="stat-number"><?php echo count(array_filter($bookings, function ($b) {
							return $b->status === 'confirmed'; })); ?></span>
						<span class="stat-label">Confirmadas</span>
					</div>
				</div>
			</div>

			<div class="booking-filters">
				<button class="filter-btn active" data-status="all">
					<i class="icon-list"></i> Todas
				</button>
				<button class="filter-btn" data-status="pending_dates">
					<i class="icon-clock"></i> Pendientes
				</button>
				<button class="filter-btn" data-status="dates_proposed">
					<i class="icon-send"></i> Fechas Enviadas
				</button>
				<button class="filter-btn" data-status="dates_rejected">
					<i class="icon-x"></i> Rechazadas
				</button>
				<button class="filter-btn" data-status="confirmed">
					<i class="icon-check"></i> Confirmadas
				</button>
			</div>

			<div class="bookings-grid">
				<?php foreach ($bookings as $booking):
					$product = get_post($booking->product_id);
					$product_image = get_the_post_thumbnail_url($booking->product_id, 'medium');
					$booking_details = maybe_unserialize($booking->booking_details);
					?>
					<div class="booking-card" data-status="<?php echo esc_attr($booking->status); ?>">
						<div class="booking-card-header">
							<div class="booking-title-section">
								<h3 class="booking-title">
									<?php echo esc_html($product->post_title); ?><br>
									<small style="font-weight: normal; font-size: 0.9em;">Pedido #<?php echo esc_html($booking->order_id); ?></small>
								</h3>
								<span class="booking-status status-<?php echo esc_attr($booking->status); ?>">
									<?php echo $this->get_status_label($booking->status); ?>
								</span>
							</div>
						</div>

						<div class="booking-content">
							<div class="booking-info-grid">
								<div class="info-section">
									<h4><i class="icon-user"></i> Cliente</h4>
									<p class="client-name"><?php echo esc_html($booking->customer_name); ?></p>
								</div>

								<div class="info-section">
									<h4><i class="icon-clock"></i> Horario Preferido</h4>
									<p class="preferred-time"><?php echo esc_html($booking->preferred_schedule); ?></p>
								</div>

								<div class="info-section">
									<h4><i class="icon-euro"></i> Precio</h4>
									<p class="booking-price"><?php echo number_format($booking->total_price, 2); ?>€</p>
								</div>

								<div class="info-section">
									<h4><i class="icon-calendar"></i> Fecha de Compra</h4>
									<p class="purchase-date"><?php echo date('d/m/Y H:i', strtotime($booking->created_at)); ?></p>
								</div>

								<?php if ($booking_details): ?>
									<div class="details-grid info-section">
										<?php
										$details = json_decode($booking_details, true);

										if (is_array($details)):
											foreach ($details as $label => $value):
												if (!empty($value)): ?>
													<div class="detail-item">
														<span class="detail-label"><?php echo esc_html($label); ?>:</span>
														<span class="detail-value"><?php echo esc_html($value); ?></span>
													</div>
												<?php endif;
											endforeach;
										else: ?>
											<div class="detail-item">
												<span class="detail-label">Error:</span>
												<span class="detail-value">Formato de datos inválido</span>
											</div>
										<?php endif; ?>
									</div>
								<?php endif; ?>
							</div>

							<?php if ($booking->status === 'pending_dates' || $booking->status === 'dates_rejected'): ?>
								<?php if ($booking->status === 'dates_rejected'): ?>
									<div class="rejection-info">
										<h4><i class="icon-alert-triangle"></i> Fechas rechazadas por el cliente</h4>
										<?php
										$suggested_range = get_post_meta($booking->id, '_customer_suggested_date_range', true);
										if ($suggested_range):
											?>
											<div class="suggested-range">
												<p><strong>Rango sugerido por el cliente:</strong></p>
												<div class="date-range">
													<span class="date-from">
														<i class="icon-calendar"></i>
														Desde: <?php echo date('d/m/Y', strtotime($suggested_range['start_date'])); ?>
													</span>
													<span class="date-to">
														<i class="icon-calendar"></i>
														Hasta: <?php echo date('d/m/Y', strtotime($suggested_range['end_date'])); ?>
													</span>
												</div>
												<?php if (!empty($suggested_range['reason'])): ?>
													<div class="customer-comments">
														<strong>Comentarios del cliente:</strong>
														<p><?php echo esc_html($suggested_range['reason']); ?></p>
													</div>
												<?php endif; ?>
											</div>
										<?php endif; ?>
									</div>
								<?php endif; ?>

								<div class="date-proposal-form">
									<h4>
										<i class="icon-calendar-plus"></i>
										<?php echo $booking->status === 'dates_rejected' ? 'Proponer nuevas fechas:' : 'Proponer fechas:'; ?>
									</h4>
									<div class="date-inputs">
										<div class="date-input-group">
											<label>Opción 1 (obligatoria)</label>
											<input type="datetime-local" class="proposed-date" required>
										</div>
										<div class="date-input-group">
											<label>Opción 2 (opcional)</label>
											<input type="datetime-local" class="proposed-date">
										</div>
										<div class="date-input-group">
											<label>Opción 3 (opcional)</label>
											<input type="datetime-local" class="proposed-date">
										</div>
									</div>
									<button class="btn-propose-dates" data-booking="<?php echo $booking->id; ?>">
										<i class="icon-send"></i>
										<span class="btn-text"><?php echo $booking->status === 'dates_rejected' ? 'Enviar nueva propuesta' : 'Enviar propuesta de fechas'; ?></span>
										<span class="btn-loading" style="display: none;">
											<i class="icon-loader"></i> Enviando...
										</span>
									</button>
								</div>
							<?php elseif ($booking->status === 'dates_proposed'): ?>
								<div class="proposed-dates">
									<h4><i class="icon-clock"></i> Fechas propuestas</h4>
									<div class="dates-list">
										<?php
										$proposed_dates = $db_manager->get_proposed_dates($booking->id);
										foreach ($proposed_dates as $date):
											if ($date->status === 'proposed'):
												?>
												<div class="proposed-date-item">
													<i class="icon-calendar"></i>
													<span><?php echo date('d/m/Y H:i', strtotime($date->proposed_date)); ?></span>
												</div>
											<?php
											endif;
										endforeach;
										?>
									</div>
									<div class="waiting-response">
										<i class="icon-clock"></i>
										<em>Esperando respuesta del cliente...</em>
									</div>
								</div>
							<?php elseif ($booking->status === 'confirmed'): ?>
								<div class="confirmed-info">
									<h4><i class="icon-check-circle"></i> Reserva Confirmada</h4>
									<?php
									$confirmed_dates = $db_manager->get_proposed_dates($booking->id);
									foreach ($confirmed_dates as $date):
										if ($date->status === 'accepted'):
											?>
											<div class="confirmed-date">
												<i class="icon-calendar-check"></i>
												<strong>Fecha confirmada:</strong>
												<?php echo date('d/m/Y H:i', strtotime($date->proposed_date)); ?>
											</div>
											<?php
											break;
										endif;
									endforeach;
									?>
								</div>
							<?php endif; ?>
							<!-- ================================== Button para hacer la descarga de la factura ==================================-->
							<button class="btn-download-invoice" data-booking-id="<?php echo esc_attr($booking->order_id); ?>">
								<i class="icon-download"></i> Descargar Factura
							</button>
						</div>
					</div>
				<?php endforeach; ?>
			</div>

			<?php if (empty($bookings)): ?>
				<div class="empty-state">
					<div class="empty-icon">
						<i class="icon-calendar-x"></i>
					</div>
					<h3>No tienes reservas aún</h3>
					<p>Las nuevas reservas aparecerán aquí cuando los clientes realicen compras.</p>
				</div>
			<?php endif; ?>
		</div>

		<?php
	}

	// Método mejorado para etiquetas de estado con tooltips
	private function get_status_label($status)
	{
		$labels = array(
			'pending_dates' => 'Pendiente de fechas',
			'dates_proposed' => 'Fechas propuestas',
			'dates_rejected' => 'Fechas rechazadas',
			'confirmed' => 'Confirmada'
		);

		return isset($labels[$status]) ? $labels[$status] : $status;
	}

	private function render_client_bookings()
	{
		$db_manager = new BookingDatabase();
		$bookings = $db_manager->get_customer_bookings(get_current_user_id());

		wp_enqueue_style(
			'client-bookings-css',
			plugin_dir_url(dirname(__FILE__)) . 'assets/client-bookings.css'
		);
		wp_enqueue_script('client-bookings', plugin_dir_url(dirname(__FILE__)) . 'assets/client-bookings.js', array('jquery'), '1.0', true);
		wp_localize_script('client-bookings', 'client_ajax', array(
			'ajax_url' => admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce('booking_nonce')
		));
		?>

		<div id="client-bookings">
			<div class="bookings-list">
				<?php foreach ($bookings as $booking):
					if ($booking->status === '0.000000' || empty($booking->status)) {
						continue;
					}
					?>
					<div class="booking-card">
						<div class="booking-header">
							<h3>
								Reserva #<?php echo $booking->order_id; ?>
							</h3>
							<span class="booking-status status-<?php echo esc_attr($booking->status); ?>">
								<?php echo $this->get_status_label($booking->status); ?>
							</span>
						</div>

						<div class="booking-details">
							<p><strong>Taller/Actividad: </strong><?php echo get_the_title($booking->product_id); ?></p>
							<p><strong>Horario preferido:</strong> <?php echo esc_html($booking->preferred_schedule); ?></p>
							<p><strong>Total:</strong> <?php echo number_format($booking->total_price, 2); ?>€</p>

							<?php
							// Mostrar entradas (tickets) si existen
							$details = json_decode($booking->booking_details, true);
							if (!empty($details)) {
								echo '<div class="booking-tickets"><strong>Entradas:</strong>';
								echo '<ul class="ticket-list">';
								foreach ($details as $key => $value) {
									echo '<li>' . esc_html($key) . ': ' . esc_html($value) . '</li>';
								}
								echo '</ul></div>';
							}
							?>
						</div>

						<?php $this->render_client_booking_status_details($booking, $db_manager); ?>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	// Nuevo método para manejar los detalles del estado de reserva
	private function render_client_booking_status_details($booking, $db_manager)
	{
		switch ($booking->status) {
			case 'pending_dates':
				echo '<div class="status-message">';
				echo '<p><em>Tu reserva está pendiente. El colaborador te contactará pronto con fechas disponibles.</em></p>';
				echo '</div>';
				break;

			case 'dates_proposed':
				echo '<div class="proposed-dates">';
				echo '<h5>Fechas disponibles - Selecciona una o rechaza para sugerir otras:</h5>';

				$proposed_dates = $db_manager->get_proposed_dates($booking->id);
				$has_proposed_dates = false;

				foreach ($proposed_dates as $date) {
					if ($date->status === 'proposed') {
						$has_proposed_dates = true;
						echo '<div class="date-option">';
						echo '<span>' . date('d/m/Y H:i', strtotime($date->proposed_date)) . '</span>';
						echo '<button class="btn-accept-date" data-booking="' . $booking->id . '" data-date="' . $date->id . '">';
						echo '<span class="btn-text">Confirmar esta fecha</span>';
						echo '<span class="btn-loading" style="display: none;">Confirmando...</span>';
						echo '</button>';
						echo '</div>';
					}
				}

				if ($has_proposed_dates) {
					echo '<div class="reject-dates-section">';
					echo '<h6>¿Ninguna fecha te conviene?</h6>';
					echo '<button class="btn-show-reject-form">Rechazar fechas y sugerir otras</button>';
					echo '<div class="reject-form" style="display: none;">';
					echo '<div class="date-range-inputs">';
					echo '<label>Sugiere un rango de fechas que te convenga:</label>';
					echo '<div class="date-inputs-row">';
					echo '<input type="date" class="suggested-start-date" placeholder="Fecha desde">';
					echo '<input type="date" class="suggested-end-date" placeholder="Fecha hasta">';
					echo '</div>';
					echo '<textarea class="rejection-reason" placeholder="Comenta por qué no te convienen las fechas propuestas (opcional)"></textarea>';
					echo '</div>';
					echo '<div class="reject-actions">';
					echo '<button class="btn-reject-dates" data-booking="' . $booking->id . '" style="display: none;">';
					echo '<span class="btn-text">Enviar sugerencia</span>';
					echo '<span class="btn-loading" style="display: none;">Enviando...</span>';
					echo '</button>';
					echo '<button class="btn-cancel-reject" style="display: none;">Cancelar</button>';
					echo '</div>';
					echo '</div>';
					echo '</div>';
				}
				echo '</div>';
				break;

			case 'dates_rejected':
				echo '<div class="status-message rejected">';
				echo '<p><strong>Has rechazado las fechas propuestas.</strong></p>';
				echo '<p><em>El colaborador te propondrá nuevas fechas pronto.</em></p>';

				$suggested_range = get_post_meta($booking->id, '_customer_suggested_date_range', true);
				if ($suggested_range) {
					echo '<div class="suggested-range">';
					echo '<p><strong>Tu sugerencia de fechas:</strong></p>';
					echo '<p>Desde: ' . date('d/m/Y', strtotime($suggested_range['start_date'])) . '</p>';
					echo '<p>Hasta: ' . date('d/m/Y', strtotime($suggested_range['end_date'])) . '</p>';
					if (!empty($suggested_range['reason'])) {
						echo '<p><strong>Tus comentarios:</strong> ' . esc_html($suggested_range['reason']) . '</p>';
					}
					echo '</div>';
				}
				echo '</div>';
				break;

			case 'confirmed':
				echo '<div class="status-message confirmed">';
				$confirmed_dates = $db_manager->get_proposed_dates($booking->id);
				$date_found = false;

				foreach ($confirmed_dates as $date) {
					if ($date->status === 'accepted' || $date->status === 'confirmed' || $date->status === 'selected') {
						echo '<p><strong>Fecha confirmada:</strong> ' . date('d/m/Y H:i', strtotime($date->proposed_date)) . '</p>';
						$date_found = true;
						break;
					}
				}
				echo '</div>';
				break;
		}
	}

	private function send_dates_email($booking_id, $dates)
	{
		$db_manager = new BookingDatabase();
		$booking = $db_manager->get_booking_by_id($booking_id);

		if (!$booking) {
			return;
		}


		// Asumiendo que en booking tienes product_id y customer_name, preferred_schedule, total_price, customer_email (ajusta según datos reales)
		$user_email = null;

		// Obtener email usuario
		if (!empty($booking->customer_user_id)) {
			$user = get_user_by('ID', $booking->customer_user_id);
			if ($user && is_email($user->user_email)) {
				$user_email = $user->user_email;
			}
		}
		// Si no hay user_id o email, usar email directo si existe en booking
		if (!$user_email && !empty($booking->customer_email) && is_email($booking->customer_email)) {
			$user_email = $booking->customer_email;
		}

		if (!$user_email) {
			error_log('send_dates_email: Email cliente no encontrado o inválido.');
			return;
		}

		// Obtener título experiencia/producto
		$experience_title = !empty($booking->product_id) ? get_the_title($booking->product_id) : 'Tu reserva';

		// Formatear fechas propuestas
		$formatted_dates = '';
		foreach ($dates as $date) {
			$formatted_dates .= '<li>' . date_i18n('d/m/Y H:i', strtotime($date)) . '</li>';
		}

		ob_start();
		?>
		<!DOCTYPE html>
		<html>

			<head>
				<meta charset="utf-8" />
				<title>Fechas propuestas</title>
				<style>
					* {
						color: black;
						font-family: "Nunito";
					}

					table {
						width: 100%;
						max-width: 600px;
						margin: 0 auto;
						border-collapse: collapse;
					}

					th,
					td {
						padding: 10px;
						text-align: center;
					}

					h1 {
						font-size: 22px;
						margin-bottom: 20px;
						font-weight: 700;
					}

					p {
						font-size: 16px;
						margin: 10px 0;
					}

					a {
						color: #f07e13;
						text-decoration: underline;
					}

					@media screen and (max-width: 600px) {
						table {
							width: 100%;
						}
					}

					#template_header_image {
						width: 100%;
						display: inline-block;
						text-align: center;
						margin: 20px 0 25px 0;
					}

					#template_header_image img {
						height: 50px;
					}

					#template_header_nav {
						background-color: #71b849;
						color: white;
						border-top-left-radius: 25px;
						border-bottom-right-radius: 25px;
						margin-bottom: 15px;
					}

					#template_header_nav td {
						height: 42px;
						vertical-align: middle;
					}

					#template_header_nav a {
						color: white;
						text-decoration: none;
						padding: 20px 5px;
						font-family: "Nunito";
						font-size: 18px;
						font-weight: 600;
					}

					h1 {
						font-size: 22px;
						margin-bottom: 35px;
						font-weight: 700;
					}

					p {
						font-size: 16px;
						margin: 0 0 5px 0;
					}

					.header {
						margin-bottom: 10px;
					}

					.header p,
					.header span {
						text-align: left;
					}

					.header p {
						margin: 0 0 10px 0;
					}

					.header span {
						font-weight: 700;
						margin: 0 0 8px 0;
						display: block;
					}

					#template_table {
						font-weight: 700;
						font-size: 18px;
						margin: 5px auto 25px;
					}

					#template_grettings {
						margin-top: 10px;
					}

					#template_grettings .quote p {
						font-style: italic;
					}

					#template_grettings .quote span {
						font-weight: 700;
						margin-top: 15px;
						display: block;
					}

					#template_grettings .grettings {
						margin: 15px 0 10px 0;
						display: block;
						text-align: left;
					}

					#template_grettings .grettings p {
						text-align: left;
					}

					#template_grettings .grettings span {
						font-weight: 700;
					}

					.footer {
						margin-top: 10px;
					}

					.footer .logo_footer img {
						height: 40px;
						margin: 10px 0 20px;
					}

					.footer td {
						padding: 0;
					}

					.footer .logo_footer {
						border-bottom: 5px solid #71b849;
					}

					.footer .aviso-privacidad,
					.footer .aviso-privacidad p {
						background-color: white;
						color: black;
						text-align: left;
						font-size: 10px;
					}

					.footer .aviso-privacidad {
						margin-top: 25px;
					}

					.body.table {
						width: fit-content;
						border: 1px solid #ccc;
						margin: 10px auto;
						max-width: 100%;
					}

					.body.table tr td:first-child {
						font-weight: 700;
						border-right: 1px solid #ccc;
					}

					.body.body.table tr td {
						text-align: left;
						padding-right: 30px;
					}

					.body.body.table tr {
						border: 1px solid #ccc;
					}

					.body.table tr td:last-child {
						font-weight: 500;
						border-right: 1px solid #ccc;
					}

					@media screen and (max-width: 600px) {
						table {
							width: 100%;
						}

						#template_header_nav td {
							display: block;
							height: auto;
						}
					}
				</style>
			</head>

			<body>
				<div id="template_header_image">
					<img src="https://ms.regalexia.com/wp-content/uploads/2023/04/logo.png" alt="Regalexia" />
				</div>
				<table id="template_header_nav">
					<tr>
						<td><a href="https://regalexia.com/experiencias/">+ Experiencias</a></td>
						<td><a href="https://regalexia.com/amigos-regalexia/">+ Amigos Regalexia</a></td>
						<td><a href="https://regalexia.com/consejos-para-padres/">+ Blog</a></td>
					</tr>
				</table>

				<table class="header">
					<tr>
						<td>
							<h1>Reserva #<?= $booking_id; ?></h1>
							<p>Hemos preparado para ti algunas opciones para realizar tu experiencia:</p>
						</td>
					</tr>
				</table>

				<table class="body table">
					<tr>
						<td>Experiencia</td>
						<td><?= $experience_title; ?></td>
					</tr>
					<tr>
						<td>ID de reserva</td>
						<td><?= $booking_id; ?></td>
					</tr>
					<tr>
						<td>Fechas propuestas</td>
						<td>
							<ul><?= $formatted_dates; ?></ul>
						</td>
					</tr>
				</table>

				<table class="body">
					<tr>
						<td>
							Recuerda que puedes revisar más detalles y confirmar una fecha accediendo a
							<a href="https://regalexia.com/administrar-reservas/">tu cuenta</a>.
						</td>
					</tr>
				</table>

				<table class="footer">
					<tr>
						<td class="logo_footer">
							<img src="https://ms.regalexia.com/wp-content/uploads/2023/04/cropped-favico.png" />
						</td>
					</tr>
					<tr>
						<td>
							<p class="aviso-privacidad">
								<b>PROTECCIÓN DE DATOS:</b>
								De conformidad con lo dispuesto en el Reglamento (UE) 2016/679, de
								27 de abril (GDPR), y la Ley Orgánica 3/2018, de 5 de diciembre
								(LOPDGDD), le informamos de que los datos personales y la dirección
								de correo electrónico del interesado, se tratarán bajo la
								responsabilidad de KIDSON, S.L. por un interés legítimo y para el
								envío de comunicaciones sobre nuestros productos y servicios, y se
								conservarán mientras ninguna de las partes se oponga a ello. Los
								datos no se comunicarán a terceros, salvo obligación legal. Le
								informamos de que puede ejercer los derechos de acceso,
								rectificación, portabilidad y supresión de sus datos y los de
								limitación y oposición a su tratamiento dirigiéndose a Aribau 168 1º1ª, 08036 Barcelona (Barcelona). E-mail:
								<a href="mailto:hola@regalexia.com">hola@regalexia.com</a>. Si
								considera que el tratamiento no se ajusta a la normativa vigente,
								podrá presentar una reclamación ante la autoridad de control en
								<a href="https://www.aepd.es/es">www.aepd.es</a>.
							</p>
						</td>
					</tr>
					<tr>
						<td>
							<p class="aviso-privacidad">
								<b>PUBLICIDAD:</b>
								En cumplimiento de lo previsto en el artículo 21 de la Ley 34/2002
								de Servicios de la Sociedad de la Información y Comercio Electrónico
								(LSSICE), si usted no desea recibir más información sobre nuestros
								productos y/o servicios, puede darse de baja enviando un correo
								electrónico a
								<a href="mailto:hola@regalexia.com">hola@regalexia.com</a>,
								indicando en el Asunto «BAJA» o «NO ENVIAR».
							</p>
						</td>
					</tr>
				</table>
			</body>

		</html>
		<?php
		$message = ob_get_clean();

		$headers = array('Content-Type: text/html; charset=UTF-8');
		$subject = 'Fechas propuestas para tu experiencia en Regalexia';

		wp_mail($user_email, $subject, $message, $headers);
	}

}
?>