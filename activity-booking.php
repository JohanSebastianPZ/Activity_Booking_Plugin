<?php
/**
 * Plugin Name: Activity Booking
 * Description: Plugin para reserva de actividades con horarios específicos
 * Version: 1.0
 * Author: Tu Nombre
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
	exit;
}
require_once plugin_dir_path(__FILE__) . 'includes/class-booking-database.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-collaborator-manager.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-booking-frontend.php';
// Clase factura
require_once plugin_dir_path(__FILE__) . 'includes/class-invoice-generator.php';

// Esto es para realizar la descargar pero mediante el panel del Woocomerce
add_action('admin_enqueue_scripts', function ($hook) {
	if (isset($_GET['page']) && $_GET['page'] === 'wc-orders') {

		// 1. Encolar el script de administración
		wp_enqueue_script(
			'booking-woo-admin-js',
			plugin_dir_url(__FILE__) . 'assets/js/admin-orders-column.js',
			['jquery'], // Añadimos jquery como dependencia
			'1.3',
			true
		);

		// 2. LOCALIZAR ab_ajax
		// Usamos el handle 'booking-woo-admin-js' para asegurar que la variable se define
		wp_localize_script('booking-woo-admin-js', 'ab_ajax', [
			'url' => admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce('booking_nonce') // Aseguramos el Nonce correcto
		]);
	}
});

// Esto es para ejecutar la descarga de la factura en el panel de administrador
add_action('wp_enqueue_scripts', function () {
	wp_enqueue_script(
		'booking-frontend-js',
		plugin_dir_url(__FILE__) . 'assets/js/booking-frontend.js',
		['jquery'],
		'1.0',
		true
	);

	wp_localize_script('booking-frontend-js', 'ab_ajax', [
		'url' => admin_url('admin-ajax.php'),
		'nonce' => wp_create_nonce('booking_nonce')
	]);
});

// Iniciar plugin principal
class ActivityBooking
{

	private $db_manager;
	private $collaborator_manager;
	private $frontend_manager;

	public function __construct()
	{
		add_action('init', array($this, 'init'));
		register_activation_hook(__FILE__, array($this, 'activate_plugin'));
		add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));


		// Inicializar componentes
		$this->db_manager = new BookingDatabase();
		$this->collaborator_manager = new CollaboratorManager();
		$this->frontend_manager = new BookingFrontend();

		add_shortcode('sync_bookings', array($this, 'manual_sync_shortcode'));
	}

	public function activate_plugin()
	{
		$this->db_manager->create_tables();
		$this->collaborator_manager->create_collaborator_role();
	}

	public function manual_sync_shortcode()
	{
		if (!current_user_can('administrator')) {
			return '<p style="color: red;">Solo los administradores pueden ejecutar la sincronización.</p>';
		}

		$message = '';
		$message_type = '';

		if (isset($_GET['sync_now'])) {
			$result = $this->db_manager->clean_and_sync_all_bookings();

			$message = '<strong>Sincronización completada:</strong><br>';
			$message .= '• Reservas eliminadas (inválidas): ' . $result['deleted'] . '<br>';
			$message .= '• Reservas creadas (faltantes): ' . $result['created'] . '<br>';
			$message .= '• Total pedidos válidos encontrados: ' . $result['total_valid_orders'];
			$message_type = 'success';
		}

		if (isset($_GET['reset_table'])) {
			global $wpdb;
			$db_manager = new BookingDatabase();

			// Vaciar completamente las tablas
			$wpdb->query("TRUNCATE TABLE " . $wpdb->prefix . "activity_booking_dates");
			$wpdb->query("TRUNCATE TABLE " . $wpdb->prefix . "activity_bookings");

			// Recrear todas las reservas desde cero
			$result = $this->db_manager->clean_and_sync_all_bookings();

			$message = '<strong>Tabla reseteada y sincronizada:</strong><br>';
			$message .= '• Reservas creadas desde cero: ' . $result['created'] . '<br>';
			$message .= '• Total pedidos válidos: ' . $result['total_valid_orders'];
			$message_type = 'success';
		}

		ob_start();
		?>
		<div style="padding: 20px; border: 1px solid #ddd; margin: 20px 0; background: #f9f9f9;">
			<h3>🔄 Sincronización de Reservas con WooCommerce</h3>

			<?php if ($message): ?>
				<div style="padding: 10px; margin: 10px 0; border-left: 4px solid #00a32a; background: #f0f8f0;">
					<?php echo $message; ?>
				</div>
			<?php endif; ?>

			<div style="margin: 20px 0;">
				<h4>📊 Estado actual:</h4>
				<?php
				global $wpdb;
				$total_bookings = $wpdb->get_var("SELECT COUNT(*) FROM " . $wpdb->prefix . "activity_bookings");
				$total_orders = count(wc_get_orders(array(
					'status' => array('completed', 'processing', 'on-hold'),
					'limit' => -1,
					'return' => 'ids'
				)));
				?>
				<p>• Total reservas en BD: <strong><?php echo $total_bookings; ?></strong></p>
				<p>• Total pedidos válidos en WooCommerce: <strong><?php echo $total_orders; ?></strong></p>
			</div>

			<div style="margin: 20px 0;">
				<h4>🔧 Opciones de sincronización:</h4>

				<p>
					<a href="<?php echo add_query_arg('sync_now', '1'); ?>" class="button button-primary" style="margin-right: 10px;" onclick="return confirm('¿Estás seguro de que quieres sincronizar las reservas?')">
						🔄 Sincronizar (Limpia inválidas y crea faltantes)
					</a>
					<small>Elimina reservas de pedidos cancelados/inexistentes y crea las faltantes</small>
				</p>

				<p>
					<a href="<?php echo add_query_arg('reset_table', '1'); ?>" class="button button-secondary" style="color: #d63384;" onclick="return confirm('⚠️ ATENCIÓN: Esto eliminará TODAS las reservas y las recreará desde cero. ¿Estás seguro?')">
						🗑️ Reset completo (Vaciar y recrear)
					</a>
					<small>⚠️ Elimina todas las reservas y las recrea desde los pedidos de WooCommerce</small>
				</p>
			</div>

			<div style="margin: 20px 0; padding: 10px; background: #fff3cd; border: 1px solid #ffeaa7;">
				<h4>ℹ️ Información:</h4>
				<ul>
					<li>Solo se consideran pedidos con estado: <strong>Completado, Procesando, En espera</strong></li>
					<li>Solo se crean reservas para productos marcados como "Es una actividad"</li>
					<li>Los productos deben tener un colaborador asignado</li>
					<li>Se eliminan automáticamente las reservas de pedidos cancelados/reembolsados</li>
				</ul>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	public function init()
	{
		// Hooks existentes...
		add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
		add_action('woocommerce_single_product_summary', array($this, 'add_booking_button'), 25);
		add_action('wp_footer', array($this, 'booking_modal_html'));
		add_action('wp_ajax_add_booking_to_cart', array($this, 'add_booking_to_cart'));
		add_action('wp_ajax_nopriv_add_booking_to_cart', array($this, 'add_booking_to_cart'));

		// NUEVOS HOOKS para sincronización con WooCommerce
		add_action('woocommerce_order_status_completed', array($this, 'create_booking_on_order_completed'));
		add_action('woocommerce_order_status_processing', array($this, 'create_booking_on_order_completed'));
		add_action('woocommerce_order_status_cancelled', array($this, 'delete_booking_on_order_cancelled'));
		add_action('woocommerce_order_status_refunded', array($this, 'delete_booking_on_order_cancelled'));
		add_action('woocommerce_order_status_failed', array($this, 'delete_booking_on_order_cancelled'));
		add_action('before_delete_post', array($this, 'delete_booking_on_order_deleted'));

		// Hook para sincronización automática
		add_action('init', array($this, 'maybe_sync_bookings'));

		// Hooks para panel de administración
		add_action('woocommerce_product_options_general_product_data', array($this, 'add_activity_fields'));
		add_action('woocommerce_process_product_meta', array($this, 'save_activity_fields'));

		// Hooks para mostrar datos en carrito
		add_filter('woocommerce_cart_item_name', array($this, 'display_booking_data_cart'), 10, 3);
		add_action('woocommerce_checkout_create_order_line_item', array($this, 'save_booking_data_order'), 10, 4);

		// Hook para precio personalizado
		add_action('woocommerce_before_calculate_totals', array($this, 'set_custom_cart_item_price'));

		// Hook para remover botón por defecto
		add_action('woocommerce_single_product_summary', array($this, 'remove_default_add_to_cart'), 1);

		// Hook para mostrar los campos en el perfil de usuario (NIF, Empresa, etc.)
		add_action('show_user_profile', array($this, 'add_collaborator_fields'));
		add_action('edit_user_profile', array($this, 'add_collaborator_fields'));

		// Hook para guardar los datos de los campos de facturación (NIF, Empresa, etc.) del Colaborador
		add_action('personal_options_update', array($this, 'save_collaborator_fields'));
		add_action('edit_user_profile_update', array($this, 'save_collaborator_fields'));

		// Hooks de fronted para mostrar campos de Facturación en "Detalles de la Cuenta"
		add_action('woocommerce_edit_account_form', array($this, 'add_frontend_collaborator_fields'));

		add_action('save_post', array($this, 'save_activity_schedules'));
	}

	// Nuevo método para mostrar los campos en el perfil de usuario 
	public function add_collaborator_fields($user)
	{
		?>
		<h1>Datos de Facturacion</h1>

		<table class="form-table">
			<tr>
				<th> <label for="empresa_colaborador">Company</label> </th>
				<td> <input class="regular-text" type="text" name="empresa_colaborador" id="empresa_colaborador" value="<?php echo esc_attr(get_user_meta($user->ID, 'empresa_colaborador', true)); ?>"> </td>
			</tr>
			<tr>
				<th> <label for="nif_colaborador">NIF</label> </th>
				<td> <input class="regular-text" type="text" name="nif_colaborador" id="nif_colaborador" value="<?php echo esc_attr(get_user_meta($user->ID, 'nif_colaborador', true)); ?>"> </td>
			</tr>
			<tr>
				<th> <label for="provincia_colaborador">Povincia</label> </th>
				<td> <input class="regular-text" type="text" name="provincia_colaborador" id="provincia_colaborador" value="<?php echo esc_attr(get_user_meta($user->ID, 'provincia_colaborador', true)); ?>"> </td>
			</tr>
			<tr>
				<th> <label for="ciudad_colaborador">Ciudad</label> </th>
				<td> <input class="regular-text" type="text" name="ciudad_colaborador" id="ciudad_colaborador" value="<?php echo esc_attr(get_user_meta($user->ID, 'ciudad_colaborador', true)); ?>"> </td>
			</tr>
		</table>
		<?php
	}

	// Nuevo método para guardar los datos de los campos en el perfil de usuario
	public function save_collaborator_fields($user_id)
	{
		if (!current_user_can('edit_user', $user_id)) {
			return;
		}

		if (isset($_REQUEST['empresa_colaborador'])) {
			$empresa = sanitize_text_field($_REQUEST['empresa_colaborador']);

			update_user_meta($user_id, 'empresa_colaborador', $empresa);
		}

		if (isset($_REQUEST['nif_colaborador'])) {
			$nif = sanitize_text_field($_REQUEST['nif_colaborador']);

			update_user_meta($user_id, 'nif_colaborador', $nif);
		}

		if (isset($_REQUEST['provincia_colaborador'])) {
			$provincia = sanitize_text_field($_REQUEST['provincia_colaborador']);

			update_user_meta($user_id, 'provincia_colaborador', $provincia);
		}


		if (isset($_REQUEST['ciudad_colaborador'])) {
			$ciudad = sanitize_text_field($_REQUEST['ciudad_colaborador']);

			update_user_meta($user_id, 'ciudad_colaborador', $ciudad);
		}
	}

	public function add_frontend_collaborator_fields($user_id)
	{

		$current_user_id = get_current_user_id();

		if ($current_user_id === 0) {
			return; // No hay usuario logueado, salimos.
		}

		$user = get_userdata($current_user_id);

		if (in_array('activity_collaborator', (array) $user->roles)) { ?>

			<h3 style="margin-top: 30px; border-bottom: 1px solid #ddd; padding-bottom: 5px;"><?php esc_html_e('Información de Facturación', 'activity-booking'); ?></h3>

			<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
				<label for="empresa_colaborador">Empresa <span class="required">*</span></label>
				<input type="text" class="woocommerce-Input woocommerce-Input--text input-text" name="empresa_colaborador" id="empresa_colaborador" value="<?php echo esc_attr(get_user_meta($current_user_id, 'empresa_colaborador', true)); ?>" />
			</p>

			<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
				<label for="nif_colaborador">NIF <span class="required">*</span></label>
				<input type="text" class="woocommerce-Input woocommerce-Input--text input-text" name="nif_colaborador" id="nif_colaborador" value="<?php echo esc_attr(get_user_meta($current_user_id, 'nif_colaborador', true)); ?>" />
			</p>

			<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
				<label for="provincia_colaborador">Provincia <span class="required">*</span></label>
				<input type="text" class="woocommerce-Input woocommerce-Input--text input-text" name="provincia_colaborador" id="vprovincia_colaborador" value="<?php echo esc_attr(get_user_meta($current_user_id, 'provincia_colaborador', true)); ?>" />
			</p>

			<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
				<label for="ciudad_colaborador">Ciudad <span class="required">*</span></label>
				<input type="text" class="woocommerce-Input woocommerce-Input--text input-text" name="ciudad_colaborador" id="ciudad_colaborador" value="<?php echo esc_attr(get_user_meta($current_user_id, 'ciudad_colaborador', true)); ?>" />
			</p>

			<?php

		}

	}
	// ========================Esto es lo original=======================================

	public function create_booking_on_order_completed($order_id)
	{
		// Verificar si ya existe una reserva para este pedido
		if ($this->db_manager->booking_exists_for_order($order_id)) {
			return; // Ya existe, no crear duplicado
		}

		$this->create_booking_record($order_id);
	}

	public function delete_booking_on_order_cancelled($order_id)
	{
		$this->db_manager->delete_booking_by_order($order_id);

		// Log para debugging (opcional)
		error_log("Reserva eliminada para pedido cancelado/reembolsado: " . $order_id);
	}

	public function delete_booking_on_order_deleted($post_id)
	{
		// Verificar si es un pedido de WooCommerce
		if (get_post_type($post_id) === 'shop_order') {
			$this->db_manager->delete_booking_by_order($post_id);

			// Log para debugging (opcional)
			error_log("Reserva eliminada para pedido borrado: " . $post_id);
		}
	}

	public function maybe_sync_bookings()
	{
		// Sincronizar solo una vez al día para no sobrecargar
		$last_sync = get_option('activity_booking_last_sync', 0);
		$current_time = time();

		// Sincronizar cada 24 horas o si es la primera vez
		if (($current_time - $last_sync) > DAY_IN_SECONDS || $last_sync == 0) {
			$this->db_manager->sync_with_woocommerce_orders();
			update_option('activity_booking_last_sync', $current_time);
		}
	}

	// Nuevo método para remover el botón por defecto
	public function remove_default_add_to_cart()
	{
		global $product;

		// Solo remover en productos de actividades
		if ($product && $product->get_meta('_is_activity') === 'yes') {
			remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30);
		}
	}

	
	// Nuevo método para establecer precio personalizado
	public function set_custom_cart_item_price($cart)
    {
        // Bloqueos estándar de WooCommerce
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }
        if (did_action('woocommerce_before_calculate_totals') >= 2) {
            return;
        }

        foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
            
            // Solo procesamos ítems que tienen tickets asociados (indicando que es una reserva de actividad)
            if (isset($cart_item['booking_tickets'])) {
                
                $product_id = $cart_item['product_id'];


                
                // 1. Recuperar TODAS las Reglas de Descuento guardadas (¡Lectura JSON!)
                // Metadato: _activity_discount_rules
                $discount_rules_raw = get_post_meta($product_id, '_activity_discount_rules', true);
                $discount_rules = $discount_rules_raw ? json_decode($discount_rules_raw, true) : array();
                
                // 2. Obtener la Distribución de Entradas Compradas
                // $tickets_comprados es un array asociativo: [tipo => cantidad]
                $tickets_comprados = $cart_item['booking_tickets'];
                
                $nuevo_subtotal_entradas = 0.00;
                $tarifa_gestion = 0.50; // Tarifa de gestión fija por RESERVA
                
                // Asegurar que las reglas sean un array usable
                if (!is_array($discount_rules)) {
                    $discount_rules = [];
                }
                
                // 3. Iterar sobre los tickets comprados para aplicar precios por tipo
                foreach ($tickets_comprados as $ticket_type => $quantity) {
                    $quantity = intval($quantity);
                    
                    if ($quantity <= 0) {
                        continue; // Saltar si la cantidad es cero
                    }

                    $descuento_aplicado = false;

                    // 4. Buscar una regla específica para este tipo de entrada (ej. "individual")
                    foreach ($discount_rules as $rule) {
                        
                        if (isset($rule['type']) && $rule['type'] === $ticket_type && 
                            isset($rule['min_qty']) && intval($rule['min_qty']) > 0) {
                            
                            $min_qty = intval($rule['min_qty']);
                            $discount_price = floatval($rule['discount_price']);

                            // 5. Aplicar Lógica de Descuento
                            if ($quantity >= $min_qty) {
                                // Descuento APLICADO: Usar el precio unitario de descuento.
                                $nuevo_subtotal_entradas += $quantity * $discount_price;
                                $descuento_aplicado = true;
                                break; // Encontramos la regla que aplica, pasar al siguiente tipo de ticket
                            }
                        }
                    }

                    // 6. Si NO se aplicó descuento (cantidad insuficiente o no hay regla)
                    if (!$descuento_aplicado) {
                        // Obtener el precio REGULAR de este tipo de entrada (ej. 86.00€)
                        $precio_regular_unitario = $this->get_regular_price_for_ticket_type($product_id, $ticket_type); 
                        
                        // Usar el precio regular unitario
                        $nuevo_subtotal_entradas += $quantity * $precio_regular_unitario;
                    }
                }
                
                // 7. Calcular el Precio Total de la RESERVA (Subtotal de Entradas + Tarifa de Gestión)
                $nuevo_precio_total = $nuevo_subtotal_entradas + $tarifa_gestion;

                // 8. Aplicar el nuevo precio total al ítem del carrito
                $cart_item['data']->set_price($nuevo_precio_total);
            }
        }
    }

	private function get_regular_price_for_ticket_type($product_id, $ticket_type_name) {
        
        $ticket_types_json = get_post_meta($product_id, '_activity_ticket_types', true);
        
        // Usar json_decode porque sabemos que el guardado es JSON.
        $saved_ticket_types = $ticket_types_json ? json_decode($ticket_types_json, true) : array();
        
        if (!empty($saved_ticket_types) && is_array($saved_ticket_types)) {
            foreach ($saved_ticket_types as $ticket) {
                // Compara el nombre del tipo de entrada
                if (isset($ticket['name']) && $ticket['name'] === $ticket_type_name) {
                    $price = isset($ticket['price']) ? floatval($ticket['price']) : 0.00;
                    if ($price > 0) {
                        return $price; 
                    }
                }
            }
        }
        
        return 0.00; 
    }

	// Método para crear registro de reserva cuando se complete el pedido
	public function create_booking_record($order_id)
	{
		// Verificar si ya existe una reserva para este pedido
		if ($this->db_manager->booking_exists_for_order($order_id)) {
			return; // Ya existe, no crear duplicado
		}

		$order = wc_get_order($order_id);

		// Solo crear reservas para pedidos completados o en procesamiento
		if (!in_array($order->get_status(), array('completed', 'processing'))) {
			return;
		}

		foreach ($order->get_items() as $item_id => $item) {
			$product_id = $item->get_product_id();
			$product = wc_get_product($product_id);

			if ($product && $product->get_meta('_is_activity') === 'yes') {
				$collaborator_id = $product->get_meta('_activity_collaborator');

				// Verificar que tiene colaborador asignado
				if (empty($collaborator_id)) {
					error_log("Producto actividad sin colaborador asignado: " . $product_id);
					continue;
				}

				$booking_data = array(
					'order_id' => $order_id,
					'product_id' => $product_id,
					'customer_id' => $order->get_customer_id() ?: 0,
					'customer_email' => $order->get_billing_email(),
					'customer_name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
					'collaborator_id' => $collaborator_id,
					'preferred_schedule' => $item->get_meta('Horario') ?: '',
					'booking_details' => $this->get_booking_details_from_item($item),
					'total_price' => $item->get_total(),
					'status' => 'pending_dates'
				);

				$result = $this->db_manager->create_booking($booking_data);

				if ($result) {
					// Notificar al colaborador sobre la nueva reserva
					$this->notify_collaborator_new_booking($booking_data);
				}
			}
		}
	}

	private function notify_collaborator_new_booking($booking_data)
	{
		$collaborator = get_user_by('ID', $booking_data['collaborator_id']);

		if (!$collaborator) {
			return;
		}

		$product_title = get_the_title($booking_data['product_id']);

		$subject = 'Nueva reserva de actividad - ' . $product_title;

		$message = "Hola " . $collaborator->display_name . ",\n\n";
		$message .= "Tienes una nueva reserva para la actividad: " . $product_title . "\n\n";
		$message .= "Cliente: " . $booking_data['customer_name'] . "\n";
		$message .= "Email: " . $booking_data['customer_email'] . "\n";
		$message .= "Horario preferido: " . $booking_data['preferred_schedule'] . "\n";
		$message .= "Total: " . number_format($booking_data['total_price'], 2) . "€\n\n";
		$message .= "Por favor, accede a tu panel de colaborador para proponer fechas disponibles.\n\n";
		$message .= "Saludos,\n";
		$message .= get_bloginfo('name');

		wp_mail($collaborator->user_email, $subject, $message);
	}

	private function get_booking_details_from_item($item)
	{
		$details = array();
		$meta_data = $item->get_meta_data();

		foreach ($meta_data as $meta) {
			$key = $meta->get_data()['key'];
			$value = $meta->get_data()['value'];

			if ($key !== 'Horario') {
				$details[$key] = $value;
			}
		}

		return json_encode($details);
	}

	public function add_activity_fields()
	{
		woocommerce_wp_checkbox(array(
			'id' => '_is_activity',
			'label' => 'Es una actividad',
			'description' => 'Marcar si este producto es una actividad con horarios'
		));

		echo '<div class="options_group">';

		$collaborators = get_users(array('role' => 'activity_collaborator'));
		$collaborator_options = array('' => 'Seleccionar colaborador');

		foreach ($collaborators as $collaborator) {
			$collaborator_options[$collaborator->ID] = $collaborator->display_name . ' (' . $collaborator->user_email . ')';
		}

		woocommerce_wp_select(array(
			'id' => '_activity_collaborator',
			'label' => 'Colaborador asignado',
			'options' => $collaborator_options,
			'description' => 'Selecciona el colaborador responsable de esta actividad'
		));

		global $post; // objeto global $post para obtener los metadatos
		$post_id = $post->ID;

		// Obtener los datos guardados
		$saved_schedules = get_post_meta($post_id, '_activity_schedules_data', true);

		// Inicializar con un bloque vacío si no hay datos guardados
		if (empty($saved_schedules) || !is_array($saved_schedules)) {
			$saved_schedules = [
				['day' => '', 'start_time' => '', 'end_time' => '']
			];
		}

		// Dias de la semana
		$options = [
			'days' => ['Lunes', 'Martes', 'Miercoles', 'Jueves', 'Viernes', 'Sabado', 'Domingo']
		];

		$options_days = $options['days'];

		$counter = 0; // Inicialización del contador para IDs/Nombres

		foreach ($saved_schedules as $schedule) {
			$current_day = $schedule['day'];
			$current_start_time = isset($schedule['start_time']) ? $schedule['start_time'] : ''; // Asegurarse de tener la llave
			$current_end_time = isset($schedule['end_time']) ? $schedule['end_time'] : '';

			$extra_class = '';

			echo '<div class="date_product' . $extra_class . '">';
			echo '<p>Fecha</p>';
			echo '<div class="container">';
			echo '<div class="date">';

			// SELECT DÍA
			woocommerce_wp_select(array(
				'id' => "_activity_schedules_days_{$counter}",
				'name' => '_activity_schedules_days[]', // Array para guardar múltiples valores
				'label' => 'Día:',
				'options' => array_combine($options_days, array_map('ucfirst', $options_days)),
				'value' => $current_day, // Carga el valor guardado
				'description' => '',
				'desc_tip' => true
			));

			// SELECT HORARIO INICIO 
			woocommerce_wp_text_input(array(
				'id' => "_activity_schedules_start_time_{$counter}",
				'name' => '_activity_schedules_start_time[]',
				'label' => __('Hora inicio', 'text-domain'),
				'value' => $current_start_time,
				'type' => 'time',
				'class' => 'short wc_input_time',
				'desc_tip' => true,
			));

			// SELECT HORARIO FIN
			woocommerce_wp_text_input(array(
				'id' => "_activity_schedules_end_time_{$counter}",
				'name' => '_activity_schedules_end_time[]', // ¡CRUCIAL!
				'label' => __('Hora fin', 'text-domain'),
				'value' => $current_end_time, // Carga el valor guardado
				'type' => 'time',             // <-- Tipo de input
				'class' => 'short wc_input_time',
				'desc_tip' => true,
			));

			// Botón Eliminar
			echo "<button type='button' class='remove_schedule_button'>Eliminar</button>";

			echo "</div>";
			echo "</div>";
			echo "</div>";

			$counter++; // Incremento
		}

		// Botón para añadir horario
		echo "<button type='button' class='add_schedule_button'>Agregar horario</button>";


		// Logica de visualizacion para tipos de entrada        

        echo '<div style="margin-bottom: 20px;">';
        

        global $post;
        $post_id = $post->ID;
        $ticket_types_json = get_post_meta($post_id, '_activity_ticket_types', true);
        $saved_ticket_types = $ticket_types_json ? json_decode($ticket_types_json, true) : array();

        // Inicializar con un bloque vacío si no hay datos guardados
        if (empty($saved_ticket_types) || !is_array($saved_ticket_types)) {
            $saved_ticket_types = [
                ['name' => '', 'price' => '']
            ];
        }

        $ticket_counter = 0;

        foreach ($saved_ticket_types as $ticket) {
            $current_name = isset($ticket['name']) ? $ticket['name'] : '';
            $current_price = isset($ticket['price']) ? $ticket['price'] : '';

            
            echo '<div class="date_product ticket_product_row">'; 
            echo '<div class="container">';
            echo '<div class="entrada">'; 

            // 1. Campo Tipo de Entrada
            woocommerce_wp_text_input(array(
                'id' => "_activity_ticket_name_{$ticket_counter}",
                'name' => '_activity_ticket_name[]', 
                'label' => __('Tipos de Entrada:', 'text-domain'),
                'value' => $current_name,
                'class' => 'short', 
                'desc_tip' => true,
            ));

            // 2. Campo precio
            woocommerce_wp_text_input(array(
                'id' => "_activity_ticket_price_{$ticket_counter}",
                'name' => '_activity_ticket_price[]', 
                'label' => __('Precio (€):', 'text-domain'),
                'value' => $current_price,
                'type' => 'number',
                'custom_attributes' => array('step' => 'any', 'min' => '0'),
                'class' => 'short wc_input_price',
                'desc_tip' => true,
            ));

            // 3. Botón Eliminar (Replicar el botón de Horarios)
            echo "<button type='button' class='remove_schedule_button'>Eliminar</button>"; 
            
            echo "</div>";
            echo "</div>";
            echo "</div>"; // Cierra date_product

            $ticket_counter++;
        }

        // Botón para añadir entrada (Necesitará su propio JS de repetición)
        echo "<button type='button' class='add_ticket_type_button'>Agregar Tipo de Entrada</button>";
        echo '</div>'; // Cierre del div de margen
		

		echo '</div>'; // Cierre del options_group

		$ticket_type_options = ['' => 'Seleccionar Tipo de Entrada'];
        // Recorremos los tipos de entrada guardados para crear las opciones del select
        foreach ($saved_ticket_types as $ticket) {
            if (!empty($ticket['name'])) {
                // Usamos el nombre del ticket como clave y valor
                $ticket_type_options[$ticket['name']] = $ticket['name']; 
            }
        }

		echo '<div style="margin-top: 20px;">'; // Contenedor para las reglas de descuento (Lo llamaremos 'discount_rules_container')

        global $post;
        $post_id = $post->ID;

		// Obtener la cadena JSON guardada de las reglas de descuento
    	$discount_rules_json = get_post_meta($post_id, '_activity_discount_rules', true);


		// AÑADIMOS LA DESCODIFICACIÓN JSON
		$saved_discount_rules = [];
		if (!empty($discount_rules_json)) {
			$decoded = json_decode($discount_rules_json, true);
			
			// Aseguramos que la decodificación fue exitosa y es un array
			if (is_array($decoded)) {
				$saved_discount_rules = $decoded;
			}
		}

		// Inicializar con un bloque vacío si no hay datos guardados después de la decodificación
		if (empty($saved_discount_rules)) {
			// Usamos un índice temporal alto (999) para que JS lo reemplace al añadir.
			$saved_discount_rules = [
				['type' => '', 'min_qty' => '', 'discount_price' => '']
			];
		}

        $rule_counter = 0;

        foreach ($saved_discount_rules as $rule) {
            $current_type = isset($rule['type']) ? $rule['type'] : '';
            $current_min_qty = isset($rule['min_qty']) ? $rule['min_qty'] : '';
            $current_discount_price = isset($rule['discount_price']) ? $rule['discount_price'] : '';

            $index = ($rule_counter == 0 && count($saved_discount_rules) == 1 && empty($current_type)) ? 999 : $rule_counter;

            // Este es el contenedor que JavaScript clonará
            echo '<div class="date_product discount_rule_row" data-index="' . esc_attr($index) . '" style="border: 1px dashed #ccc; padding: 10px; margin-bottom: 10px;">'; 
            echo '<div class="container">';
            echo '<div class="discount-rule">'; 
            
            // 1. CAMPO CRUCIAL: TIPO DE ENTRADA APLICABLE
            woocommerce_wp_select(array(
                'id' => "_activity_discount_type_{$index}",
                // Nombre del array: _activity_discount_rules[índice][type]
                'name' => "_activity_discount_rules[{$index}][type]", 
                'label' => __('Tipo de Entrada Aplicable:', 'text-domain'),
                // Usamos las opciones de tipos de entrada que generamos antes
                'options' => $ticket_type_options, 
                'value' => $current_type,
                'class' => 'short', 
                'desc_tip' => true,
            ));

            // 2. Campo Cantidad Mínima
            woocommerce_wp_text_input(array(
                'id' => "_activity_discount_min_qty_{$index}",
                'name' => "_activity_discount_rules[{$index}][min_qty]", 
                'label' => __('Cantidad Mínima para Descuento', 'text-domain'),
                'value' => $current_min_qty,
                'type' => 'number',
                'placeholder' => 'Ej: 3',
                'class' => 'short wc_input_price',
                'custom_attributes' => array('step' => '1', 'min' => '2')
            ));

            // 3. Campo Precio con Descuento
            woocommerce_wp_text_input(array(
                'id'          => "_activity_discount_price_{$index}",
                'name'        => "_activity_discount_rules[{$index}][discount_price]", 
                'label'       => __('Precio Unitario de Descuento', 'text-domain'),
                'value'       => $current_discount_price, 
                'placeholder' => 'Ej: 30.00',
                'class'       => 'short wc_input_price',
                'custom_attributes' => array('step' => 'any', 'min' => '0')
            ));

            // 4. Botón Eliminar
            echo "<button type='button' class='remove_schedule_button remove_discount_rule'>Eliminar Regla</button>"; 
            
            echo "</div>"; // Cierra discount-rule
            echo "</div>"; // Cierra container
            echo "</div>"; // Cierra discount_rule_row

            $rule_counter++;
        }

        // Botón para añadir una nueva regla
        echo "<button type='button' class='add_discount_rule_button'>Agregar Regla de Descuento</button>";
        echo '</div>';
		?>


		<!--
		Este bloque javascript permite agregar nuevos horarios,  eliminar horarios existentes, mantemiendo siempre un horario en uso 
		y permitiendo la actualizacion correcta del DOM.
		-->
		<script type="text/javascript">
			jQuery(document).ready(function ($) {

				// Función para actualizar el estado de los botones (Ocultar/Mostrar Eliminar)
				function update_schedule_buttons() {
					var $schedules = $('.date_product');
					var total = $schedules.length;

					$schedules.each(function (index) {
						var $button = $(this).find('.remove_schedule_button');

						if (total === 1 && index === 0) {
							$button.hide();
						} else {
							$button.show();
						}
					});
				}

				var counter = $('.date_product').length;
				var template = $('.date_product').first().clone();

				// 1. Limpiar la plantilla para futuras copias (Importante para que los nuevos NO estén ocultos).
				template.removeClass('is-initial-block');
				template.find('.remove_schedule_button').show(); // Asegura que el botón de la copia sea visible

				// 2. Inicializar el estado de los botones al cargar (ocultar el botón si solo hay 1)
				update_schedule_buttons();

				// FUNCIONALIDAD AGREGAR
				$('.add_schedule_button').on('click', function (e) {
					e.preventDefault();

					var new_schedule = template.clone();

					// Limpiar valores clonados y actualizar IDs únicos
					new_schedule.find('select').each(function () {
						var old_id = $(this).attr('id');
						var new_id = old_id.substring(0, old_id.lastIndexOf('_')) + '_' + counter;
						$(this).attr('id', new_id);
						$(this).val(''); // Resetear el valor
					});

					// Insertar la nueva estructura antes del botón 'Agregar Fecha'
					$(this).before(new_schedule);

					counter++;
					update_schedule_buttons(); // Actualiza el estado (ahora muestra todos)
				});

				// FUNCIONALIDAD ELIMINAR
				$(document).on('click', '.remove_schedule_button', function (e) {
					e.preventDefault();

					// Comprueba que no estamos eliminando el último bloque
					if ($('.date_product').length > 1) {
						$(this).closest('.date_product').remove();
						// Llama a la función después de un breve retraso para que el DOM se actualice antes del recuento.
						setTimeout(update_schedule_buttons, 10);
					}
				});

			});
		</script>
		<style>
			.date_product {
				display: flex;
				gap: 10px;
			}

			.date {
				display: flex;
				border: 1px solid black;
				border-radius: 5px;
				flex-grow: 1;
				gap: 15px;
				padding: 10px;
				margin-bottom: 10px;
			}

			.date .form-field {
				display: flex !important;
				flex-direction: row;
				align-items: center;
				gap: 6px;
				margin: 0 !important;
				padding: 0 !important;
				width: auto !important;
			}

			.date .form-field label {
				margin: 0 !important;
				padding: 0 !important;
				width: auto !important;
				float: none !important;
				white-space: nowrap;
			}

			.date .form-field select {
				flex: 1 1 auto !important;
				width: auto !important;
				min-width: 0 !important;
			}


			/* Ocultamiento del botón inicial solo por la clase que el JS añade si es necesario */
			.date_product.is-initial-block .remove_schedule_button {
				display: none !important;
			}

			.woocommerce_options_panel .form-field[class*='_activity_schedules_days_'],
			.woocommerce_options_panel .form-field[class*='_activity_schedules_start_time_'],
			.woocommerce_options_panel .form-field[class*='_activity_schedules_end_time_'] {
				padding: 0 !important;
				margin: 0 !important;
			}

			.woocommerce_options_panel input[type=time].short {
				width: 100%;
			}
		</style>
		<?php
	}

	// =========================================================
    // MÉTODO AUXILIAR PARA RENDERIZAR UNA FILA (REPETIDOR)
    // =========================================================
    private function render_ticket_row($index, $id_value, $price_value, $select_options) {
        ?>
        <div class="date ticket-type-row" data-row="<?php echo esc_attr($index); ?>" 
            style="padding: 10px; margin-bottom: 10px; border: 1px solid #ddd; display: flex; align-items: center; justify-content: space-between;">
            
            <?php woocommerce_wp_hidden_input([
                'id' => "_activity_ticket_types[{$index}][id]",
                'value' => esc_attr($id_value),
                'name' => "_activity_ticket_types[{$index}][id]"
            ]); ?>

            <p class="form-field type_select_field" style="display: flex; gap: 5px; align-items: center; margin: 0;">
                <label for="_activity_ticket_types[<?php echo esc_attr($index); ?>][id]">Tipo de Entrada:</label>
                <select name="_activity_ticket_types[<?php echo esc_attr($index); ?>][id]" id="_activity_ticket_types[<?php echo esc_attr($index); ?>][id]" class="short">
                <?php foreach ($select_options as $key => $label): ?>
                    <option value="<?php echo esc_attr($key); ?>" <?php selected($id_value, $key, false); ?>>
                        <?php echo esc_html($label); ?>
                    </option>
                <?php endforeach; ?>
                </select>
            </p>

            <?php woocommerce_wp_text_input([
                'id' => "_activity_ticket_types[{$index}][price]",
                'label' => 'Precio (€)',
                'placeholder' => '0.00',
                'value' => esc_attr($price_value),
                'name' => "_activity_ticket_types[{$index}][price]",
                'data_type' => 'price',
                'wrapper_class' => 'form-field',
                'style' => 'width: 100px; margin: 0;' // Estilo para uniformidad de inputs
            ]); ?>

            <button type="button" class="remove-ticket-row button button-small">Eliminar</button>
            
        </div>
        <?php
    }

	// Dentro de la clase ActivityBooking, añade este nuevo método:
	public function enqueue_admin_scripts($hook) {
        
        // Verifica si estamos en la página de edición de un post existente ('post.php') 
        if ( 'post.php' === $hook || 'post-new.php' === $hook ) {

            global $post;
            
            // Verifica que el tipo de post sea 'product' (es decir, la edición de un producto de WooCommerce)
            if ( $post && 'product' === $post->post_type ) {
                
                // Encolar el script (Ruta verificada)
                wp_enqueue_script(
                    'booking-admin-repeater-js',
                    plugin_dir_url(__FILE__) . 'assets/js/admin-repeater.js', 
                    array('jquery'),
                    // Usamos la versión de tiempo actual para forzar la recarga en el navegador (útil para el debugging)
                    time(), 
                    true
                );
            }
        }
    }

	public function save_activity_schedules($post_id)
	{
		// Verificacion permisos del usuario
		if (!current_user_can('edit_post', $post_id)) {
			return;
		}

		if (
			isset($_POST['_activity_schedules_days']) && is_array($_POST['_activity_schedules_days']) &&
			isset($_POST['_activity_schedules_start_time']) && is_array($_POST['_activity_schedules_start_time']) &&
			isset($_POST['_activity_schedules_end_time']) && is_array($_POST['_activity_schedules_end_time'])
		) {

			$days = array_map('sanitize_text_field', $_POST['_activity_schedules_days']);
			$start_times = array_map('sanitize_text_field', $_POST['_activity_schedules_start_time']); // <-- Obtener horas de inicio
			$end_times = array_map('sanitize_text_field', $_POST['_activity_schedules_end_time']);   // <-- Obtener horas de fin

			$schedules = [];

			// Combinar Día, Hora de Inicio y Hora de Fin
			foreach ($days as $index => $day) {
				$start_time = isset($start_times[$index]) ? $start_times[$index] : '';
				$end_time = isset($end_times[$index]) ? $end_times[$index] : '';

				// Solo guarda si el día NO está vacío Y si AMBAS horas están presentes
				if (!empty($day) && !empty($start_time) && !empty($end_time)) {
					$schedules[] = [
						'id' => $index + 1,
						'day' => $day,
						'start_time' => $start_time, // <-- Guardar hora de inicio
						'end_time' => $end_time,   // <-- Guardar hora de fin
					];
				}
			}

			if (!empty($schedules)) {
				update_post_meta($post_id, '_activity_schedules_data', $schedules);
			} else {
				delete_post_meta($post_id, '_activity_schedules_data');
			}
		} else {
			delete_post_meta($post_id, '_activity_schedules_data');
		}
	}

	public function save_activity_fields($post_id)
	{
		if (!current_user_can('edit_post', $post_id)) {
			return;
		}

		$is_activity = isset($_POST['_is_activity']) ? 'yes' : '';
		update_post_meta($post_id, '_is_activity', $is_activity);

		if (isset($_POST['_activity_collaborator'])) {
			$collaborator_id = intval($_POST['_activity_collaborator']);
			update_post_meta($post_id, '_activity_collaborator', $collaborator_id);
		}

		if (isset($_POST['_activity_schedules_days_'])) {
			$schedules = wp_kses_post(wp_unslash($_POST['_activity_schedules_days_']));
			update_post_meta($post_id, '_activity_schedules_days_', $schedules);
		}

		if (isset($_POST['_activity_schedules_time_'])) {
			$schedules = wp_kses_post(wp_unslash($_POST['_activity_schedules_time_']));
			update_post_meta($post_id, '_activity_schedules_time_', $schedules);
		}

		//Código para tipos de entrada (Json encode).
        if (
            isset($_POST['_activity_ticket_name']) && is_array($_POST['_activity_ticket_name']) &&
            isset($_POST['_activity_ticket_price']) && is_array($_POST['_activity_ticket_price'])
        ) {
            $names = array_map('sanitize_text_field', $_POST['_activity_ticket_name']);
            $prices = array_map('sanitize_text_field', $_POST['_activity_ticket_price']);
            
            $ticket_types = [];
            
            // Combinar Nombres y Precios
            foreach ($names as $index => $name) {
                $price = isset($prices[$index]) ? $prices[$index] : '';
                
                // Solo guardar si el Nombre y el Precio no están vacíos
                if (!empty($name) && !empty($price)) {
                    $ticket_types[] = [
                        
                        'id' => $index + 1, 
                        'name' => $name,
                        'price' => floatval($price),
                    ];
                }
            }

            if (!empty($ticket_types)) {
                // Conversión: ARRAY PHP a STRING JSON
                $ticket_types_json = json_encode($ticket_types);
                update_post_meta($post_id, '_activity_ticket_types', $ticket_types_json);
            } else {
                delete_post_meta($post_id, '_activity_ticket_types');
            }
        } else {
            delete_post_meta($post_id, '_activity_ticket_types');
        }

		if(!current_user_can('edit_post',$post_id)){
			return;
		}

		// Obtener el array de reglas enviado por POST
		$discount_rules = isset($_POST['_activity_discount_rules']) ? $_POST['_activity_discount_rules'] : array();
		
		$clean_rules = array();

		if (!empty($discount_rules) && is_array($discount_rules)) {
			foreach ($discount_rules as $rule) {
				// Se asume que $rule es un array con 'type', 'min_qty', y 'discount_price'
				
				$type           = isset($rule['type']) ? sanitize_text_field($rule['type']) : '';
				$min_qty        = isset($rule['min_qty']) ? floatval($rule['min_qty']) : 0; // Usar floatval o intval
				$discount_price = isset($rule['discount_price']) ? floatval($rule['discount_price']) : 0.00; // Usar floatval para precios
				
				// Opcional: Solo guardar la regla si tiene un tipo y una cantidad mínima válida
				if (!empty($type) && $min_qty > 0) {
					$clean_rules[] = array(
						'type'           => $type,
						'min_qty'        => $min_qty,
						'discount_price' => $discount_price,
					);
				}
			}
		}

		// Guardar el array limpio de reglas en un solo metadato
		$rules_json = json_encode($clean_rules);
		update_post_meta($post_id, '_activity_discount_rules', $rules_json);
	}

	public function enqueue_scripts()
    {
        if (is_product()) {
            global $product; // 1. Obtenemos el objeto del producto actual
            
            // 2. Recuperamos las reglas de descuento guardadas en los metadatos
            $discount_rules_json = $product->get_meta('_activity_discount_rules');
            $discount_rules = $discount_rules_json ? json_decode($discount_rules_json, true) : array();
            
            // 3. Encolamos el script y el estilo
            // Usamos time() como versión para asegurar que los cambios se vean al instante sin caché
            wp_enqueue_script('activity-booking-js', plugin_dir_url(__FILE__) . 'assets/booking.js', array('jquery'), time(), true);
            wp_enqueue_style('activity-booking-css', plugin_dir_url(__FILE__) . 'assets/booking.css', array(), '1.0');

            // 4. Pasamos los datos de AJAX necesarios
            wp_localize_script('activity-booking-js', 'booking_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('booking_nonce'),
                'cart_url' => wc_get_cart_url(),
                'checkout_url' => wc_get_checkout_url()
            ));

            // 5. Pasamos las reglas de descuento al JavaScript
            wp_localize_script('activity-booking-js', 'DISCOUNT_RULES', $discount_rules); 
        }
    }

	public function add_booking_button()
	{
		global $product;

		// Solo mostrar en productos de actividades
		if ($product && $product->get_meta('_is_activity') === 'yes') {
			echo '<div class="divisionDoble contenedorBotones">';
			echo '<div class="primerContenedor">';
			echo '<button type="button" id="open-booking-modal" class="single_add_to_cart_button button alt">Comprar entrada</button>';
			echo '</div>';
			echo '</div>';
		}
	}

	public function booking_modal_html()
    {
        if (!is_product())
            return;

        global $product;
        if (!$product || $product->get_meta('_is_activity') !== 'yes')
            return;

        // 1. Obtener datos y reglas de descuento
        $ticket_types_json = $product->get_meta('_activity_ticket_types');
        $ticket_types = $ticket_types_json ? json_decode($ticket_types_json, true) : array();
        $schedules = $product->get_meta('_activity_schedules_data');
        
        // Obtenemos las reglas para poder usarlas si fuera necesario en el HTML
        $discount_rules_json = $product->get_meta('_activity_discount_rules');
        $discount_rules = $discount_rules_json ? json_decode($discount_rules_json, true) : array();

        if (empty($schedules) || !is_array($schedules)) {
            $schedules = array(
                array('id' => '1', 'day' => 'Sábado', 'start_time' => '17:00', 'end_time' => '19:00'),
                array('id' => '2', 'day' => 'Domingo', 'start_time' => '10:00', 'end_time' => '12:00')
            );
        }

        if (empty($ticket_types)) {
            $ticket_types = array(
                array('id' => 'adult', 'name' => 'Adulto', 'price' => '20')
            );
        }

        ?>
        <div id="booking-modal" class="booking-modal" style="display: none;">
            <div class="booking-modal-content">
                <div class="booking-header">
                    <h3>Compra tu entrada</h3>
                    <span class="close-modal">&times;</span>
                </div>

                <div class="booking-body">
                    <div class="schedule-selection">
                        <div class="schedule-info">
                            <h4 class="schedule-title">Selecciona tu fecha preferida:</h4>
                            <div class="schedule-times">
                                <?php foreach ($schedules as $schedule): ?>
                                    <label>
                                        <input type="radio" name="booking_schedule" value="<?php echo esc_attr($schedule['id']); ?>" required>
                                        <strong> <?php echo esc_html($schedule['day']) . ' de ' . esc_html($schedule['start_time']) . ' a ' . esc_html($schedule['end_time']); ?></strong>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <div class="experience-title">
                        <h4><?php echo $product->get_name(); ?></h4>
                    </div>

                    <div class="ticket-selection">
                        <?php foreach ($ticket_types as $ticket): ?>
                            <div class="ticket-row">
                                <div class="ticket-info">
                                    <span class="ticket-type"><?php echo esc_html($ticket['name']); ?></span>
                                    <div class="ticket-price">
                                        <span class="price" data-price="<?php echo esc_attr($ticket['price']); ?>"><?php echo esc_html($ticket['price']); ?>€</span>
                                        <small>+0,50€ gastos gestión</small>
                                    </div>
                                </div>
                                <div class="quantity-selector">
                                    <span class="quantity-display">0</span>
                                    <div class="quantity-controls">
                                        <button type="button" class="quantity-btn plus" data-ticket="<?php echo esc_html($ticket['name']); ?>">+</button>
                                        <button type="button" class="quantity-btn minus" data-ticket="<?php echo esc_html($ticket['name']); ?>">-</button>
                                    </div>
                                    <input type="hidden" name="ticket_quantity[<?php echo esc_attr($ticket['id']); ?>]" value="0" class="ticket-quantity">
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="booking-summary" style="display:none;">
                        <div class="summary-row">
                            <span>Subtotal:</span>
                            <span id="subtotal">0€</span>
                        </div>
                        
						<div class="summary-row discount-row" style="display:none; color: #27ae60; font-weight: bold;"> 
							<span>¡Descuento por cantidad!:</span>
							<span id="discount-amount">0€</span>
						</div>

                        <div class="summary-row">
                            <span>Gastos de gestión:</span>
                            <span id="management-fee">0€</span>
                        </div>
                        <div class="summary-row total-row">
                            <span><strong>Total:</strong></span>
                            <span id="total-price"><strong>0€</strong></span>
                        </div>
                    </div>

                    <button type="button" id="confirm-booking" class="booking-confirm-btn" disabled>
                        <span class="btn-text">Comprar</span>
                    </button>
                </div>
            </div>
        </div>

		<?php
        // --- AQUÍ PEGAS EL CÓDIGO NUEVO ---
        
$reglas_crudo = $product->get_meta('_activity_discount_rules');
        ?>
        <script type="text/javascript">
            // Forzamos la creación de la variable global
            window.REGLAS_DESCUENTO = <?php echo $reglas_crudo ? $reglas_crudo : '[]'; ?>;
            console.log("Reglas cargadas correctamente:", window.REGLAS_DESCUENTO);
        </script>

        <input type="hidden" id="current-product-id" value="<?php echo $product->get_id(); ?>">
        <?php
    }

	public function add_booking_to_cart()
    {
        check_ajax_referer('booking_nonce', 'nonce');

        $product_id = intval($_POST['product_id']);
        $schedule_id = sanitize_text_field($_POST['schedule_id']);
        $tickets_post = isset($_POST['tickets']) ? $_POST['tickets'] : array(); // Renombramos la variable de entrada

        if (empty($product_id) || empty($schedule_id)) {
            wp_send_json_error(array('message' => 'Datos incompletos'));
        }

        // Verificar si hay al menos un ticket con cantidad mayor a 0
        $has_tickets = false;
        $total_price = 0;
        $total_quantity = 0;

        // Obtener información de tipos de entrada del producto
        $product = wc_get_product($product_id);
        $ticket_types_json = $product->get_meta('_activity_ticket_types');
        $ticket_types = $ticket_types_json ? json_decode($ticket_types_json, true) : array();

        // Nuevo array que guardará los tickets con el NOMBRE como clave
        $tickets_for_cart = array();
        
        // Calcular precio total y cantidad total
        foreach ($tickets_post as $ticket_id => $quantity) { // Iteramos sobre ID => Cantidad
            $quantity = intval($quantity);
            if ($quantity > 0) {
                $has_tickets = true;
                $total_quantity += $quantity;

                // Buscar el precio y el NOMBRE del tipo de entrada
                foreach ($ticket_types as $ticket_type) {
                    if ($ticket_type['id'] == $ticket_id) {
                        
                        // 1. CÁLCULO DE PRECIO
                        $ticket_name = $ticket_type['name']; // Obtener el nombre ('individual', 'Grupal')
                        $ticket_price = floatval($ticket_type['price']);
                        $total_price += ($ticket_price * $quantity);
                        
                        // 2. CREAR EL ARRAY PARA EL CARRITO USANDO EL NOMBRE COMO CLAVE
                        $tickets_for_cart[$ticket_name] = $quantity; 
                        
                        break;
                    }
                }
            }
        }

        // Aqui se agrega el 0.50 al precio del producto, por gestion
        $management_fee = 0.50;
        $total_price += $management_fee;

        if (!$has_tickets) {
            wp_send_json_error(array('message' => 'Debe seleccionar al menos una entrada'));
        }

        // Preparar datos del carrito con precio personalizado
        $cart_item_data = array(
            'booking_schedule' => $schedule_id,
            'booking_tickets' => $tickets_for_cart, // USAMOS EL ARRAY CORREGIDO
            'booking_total_price' => $total_price,
            'unique_key' => md5(microtime() . rand())
        );

        // Añadir al carrito con cantidad 1 pero precio personalizado
        $added = WC()->cart->add_to_cart($product_id, 1, 0, array(), $cart_item_data);

        if ($added) {
            wp_send_json_success(array('message' => 'Producto añadido al carrito'));
        } else {
            wp_send_json_error(array('message' => 'Error al añadir al carrito'));
        }
    }

	public function display_booking_data_cart($name, $cart_item, $cart_item_key)
	{
		if (isset($cart_item['booking_schedule']) && isset($cart_item['booking_tickets'])) {
			$schedule_info = $this->get_schedule_info($cart_item['booking_schedule'], $cart_item['product_id']);
			$name .= '<br><small><strong>Horario:</strong> ' . $schedule_info . '</small>';

			$name .= '<br><small><strong>Entradas:</strong></small>';
			foreach ($cart_item['booking_tickets'] as $ticket_id => $quantity) {
				if ($quantity > 0) {
					$ticket_info = $this->get_ticket_info($ticket_id, $cart_item['product_id']);
					$price_per_ticket = floatval($ticket_info['price']); // Incluir gastos de gestión
					$name .= '<br><small>• ' . $ticket_info['name'] . ' x' . $quantity . ' (' . number_format($price_per_ticket, 2) . '€ c/u)</small>';
				}
			}
		}
		return $name;
	}

	public function save_booking_data_order($item, $cart_item_key, $values, $order)
	{
		if (isset($values['booking_schedule'])) {
			$item->add_meta_data('Horario', $this->get_schedule_info($values['booking_schedule'], $values['product_id']));
		}

		if (isset($values['booking_tickets'])) {
			foreach ($values['booking_tickets'] as $ticket_id => $quantity) {
				if ($quantity > 0) {
					$ticket_info = $this->get_ticket_info($ticket_id, $values['product_id']);
					$item->add_meta_data($ticket_info['name'], $quantity);
				}
			}
		}
	}

	// Métodos auxiliares
	private function get_schedule_info($schedule_id, $product_id)
	{
		$product = wc_get_product($product_id);
		$schedules = $product->get_meta('_activity_schedules_data');

		if (empty($schedules) || !is_array($schedules)) {
			return 'Horario no encontrado';
		}

		foreach ($schedules as $schedule) {
			if (isset($schedule['id']) && $schedule['id'] == $schedule_id) {
				return $schedule['day'] . ' de ' . $schedule['start_time'] . ' a ' . $schedule['end_time'];
			}
		}

		return 'Horario no encontrado';
	}

	private function get_ticket_info($ticket_id, $product_id)
	{
		$product = wc_get_product($product_id);
		$ticket_types_json = $product->get_meta('_activity_ticket_types');
		$ticket_types = $ticket_types_json ? json_decode($ticket_types_json, true) : array();

		foreach ($ticket_types as $ticket) {
			if ($ticket['id'] == $ticket_id) {
				return array('name' => $ticket['name'], 'price' => $ticket['price']);
			}
		}

		return array('name' => 'Entrada', 'price' => 0);
	}
}

new ActivityBooking();
?>