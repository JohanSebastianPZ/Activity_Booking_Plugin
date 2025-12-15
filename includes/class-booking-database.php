<?php
if (!defined('ABSPATH')) {
	exit;
}

class BookingDatabase
{
	private $table_name;
	private $dates_table_name;

	public function __construct()
	{
		global $wpdb;
		$this->table_name = $wpdb->prefix . 'activity_bookings';
		$this->dates_table_name = $wpdb->prefix . 'activity_booking_dates';
	}

	public function create_tables()
	{
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		// Tabla principal de reservas
		$sql1 = "CREATE TABLE {$this->table_name} (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            order_id mediumint(9) NOT NULL,
            product_id mediumint(9) NOT NULL,
            customer_id mediumint(9) NOT NULL,
            customer_email varchar(255) NOT NULL,
            customer_name varchar(255) NOT NULL,
            collaborator_id mediumint(9) NOT NULL,
            preferred_schedule text,
            booking_details text,
            total_price decimal(10,2) NOT NULL,
            status varchar(50) DEFAULT 'pending_dates',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";

		// Tabla de fechas propuestas
		$sql2 = "CREATE TABLE {$this->dates_table_name} (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            booking_id mediumint(9) NOT NULL,
            proposed_date datetime NOT NULL,
            status varchar(50) DEFAULT 'proposed',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            FOREIGN KEY (booking_id) REFERENCES {$this->table_name}(id)
        ) $charset_collate;";

		require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
		dbDelta($sql1);
		dbDelta($sql2);
	}

	public function create_booking($data)
	{
		global $wpdb;

		if (empty($data['status']) || !is_string($data['status']) || is_numeric($data['status'])) {
			$data['status'] = 'pending_dates';
		}

		return $wpdb->insert(
			$this->table_name,
			$data,
			array('%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%f', '%s')
		);
	}

	public function get_bookings_by_order($order_id)
	{
		global $wpdb;

		$table_name = $wpdb->prefix . 'activity_bookings'; // Ajusta el nombre de tu tabla

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $table_name WHERE order_id = %d",
				$order_id
			)
		);

		return $results;
	}

	public function get_collaborator_bookings($collaborator_id, $status = null)
	{
		global $wpdb;

		$sql = "SELECT * FROM {$this->table_name} WHERE collaborator_id = %d";
		$params = array($collaborator_id);

		if ($status) {
			$sql .= " AND status = %s";
			$params[] = $status;
		}

		$sql .= " ORDER BY created_at DESC";

		return $wpdb->get_results($wpdb->prepare($sql, $params));
	}

    public function get_all_bookings($status = null)
    {
        global $wpdb;

        $sql = "SELECT * FROM {$this->table_name}";
        $params = array();

        // Si se pasa un estado, agregamos cláusula WHERE
        if ($status !== null) {
            $sql .= " WHERE status = %s";
            $params[] = $status;
        }

        $sql .= " ORDER BY created_at DESC";

        // Si no hay parámetros, no uses prepare
        if (!empty($params)) {
            return $wpdb->get_results($wpdb->prepare($sql, $params));
        } else {
            return $wpdb->get_results($sql);
        }
    }


	public function get_customer_bookings($customer_id, $status = null)
	{
		global $wpdb;

		$sql = "SELECT * FROM {$this->table_name} WHERE customer_id = %d";
		$params = array($customer_id);

		if ($status) {
			$sql .= " AND status = %s";
			$params[] = $status;
		}

		$sql .= " ORDER BY created_at DESC";

		return $wpdb->get_results($wpdb->prepare($sql, $params));
	}

	public function update_booking_status($booking_id, $status)
	{
		global $wpdb;

		return $wpdb->update(
			$this->table_name,
			array('status' => $status),
			array('id' => $booking_id),
			array('%s'),
			array('%d')
		);
	}

	public function add_proposed_dates($booking_id, $dates)
	{
		global $wpdb;

		foreach ($dates as $date) {
			$wpdb->insert(
				$this->dates_table_name,
				array(
					'booking_id' => $booking_id,
					'proposed_date' => $date
				),
				array('%d', '%s')
			);
		}
	}

	public function get_proposed_dates($booking_id)
	{
		global $wpdb;

		return $wpdb->get_results($wpdb->prepare(
			"SELECT * FROM {$this->dates_table_name} WHERE booking_id = %d ORDER BY proposed_date ASC",
			$booking_id
		));
	}

	public function accept_date($booking_id, $date_id)
	{
		global $wpdb;

		// Marcar todas las fechas de esta reserva como rechazadas primero
		$wpdb->update(
			$this->dates_table_name,
			array('status' => 'rejected'),
			array('booking_id' => $booking_id),
			array('%s'),
			array('%d')
		);

		// Marcar la fecha seleccionada como aceptada
		$result = $wpdb->update(
			$this->dates_table_name,
			array('status' => 'accepted'),
			array('id' => $date_id),
			array('%s'),
			array('%d')
		);

		// Actualizar estado de la reserva
		if ($result !== false) {
			$this->update_booking_status($booking_id, 'confirmed');
			return true;
		}

		return false;
	}

	public function reject_proposed_dates($booking_id, $suggested_date_range = null)
	{
		global $wpdb;

		// Marcar todas las fechas propuestas como rechazadas
		$wpdb->update(
			$this->dates_table_name,
			array('status' => 'rejected'),
			array('booking_id' => $booking_id, 'status' => 'proposed'),
			array('%s'),
			array('%d', '%s')
		);

		// Actualizar el estado de la reserva
		$this->update_booking_status($booking_id, 'dates_rejected');

		// Si hay sugerencia de rango de fechas, guardarla
		if ($suggested_date_range) {
			update_post_meta($booking_id, '_customer_suggested_date_range', $suggested_date_range);
		}

		return true;
	}

	public function get_booking_by_id($booking_id)
	{
		global $wpdb;

		return $wpdb->get_row($wpdb->prepare(
			"SELECT * FROM {$this->table_name} WHERE id = %d",
			$booking_id
		));
	}

	public function clear_previous_proposed_dates($booking_id)
	{
		global $wpdb;

		return $wpdb->delete(
			$this->dates_table_name,
			array('booking_id' => $booking_id),
			array('%d')
		);
	}

	public function delete_booking_by_order($order_id)
	{
		global $wpdb;

		// Primero obtener las reservas asociadas al pedido
		$bookings = $wpdb->get_results($wpdb->prepare(
			"SELECT id FROM {$this->table_name} WHERE order_id = %d",
			$order_id
		));

		// Eliminar fechas propuestas de cada reserva
		foreach ($bookings as $booking) {
			$wpdb->delete(
				$this->dates_table_name,
				array('booking_id' => $booking->id),
				array('%d')
			);
		}

		// Eliminar las reservas
		return $wpdb->delete(
			$this->table_name,
			array('order_id' => $order_id),
			array('%d')
		);
	}

	public function get_booking_by_order($order_id)
	{
		global $wpdb;

		return $wpdb->get_results($wpdb->prepare(
			"SELECT * FROM {$this->table_name} WHERE order_id = %d",
			$order_id
		));
	}

	public function sync_with_woocommerce_orders()
	{
		global $wpdb;

		// Obtener todas las reservas
		$bookings = $wpdb->get_results("SELECT * FROM {$this->table_name}");

		foreach ($bookings as $booking) {
			$order = wc_get_order($booking->order_id);

			// Si el pedido no existe o está cancelado/reembolsado, eliminar la reserva
			if (!$order || in_array($order->get_status(), array('cancelled', 'refunded', 'failed'))) {
				$this->delete_booking_by_order($booking->order_id);
			}
		}

		return true;
	}

	public function booking_exists_for_order($order_id)
	{
		global $wpdb;

		$count = $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*) FROM {$this->table_name} WHERE order_id = %d",
			$order_id
		));

		return $count > 0;
	}

	public function clean_and_sync_all_bookings()
	{
		global $wpdb;

		// Obtener todos los pedidos de WooCommerce con productos de actividad
		$valid_bookings = array();

		// Buscar todos los pedidos con estado válido (completados, procesando, en espera)
		$orders = wc_get_orders(array(
			'status' => array('completed', 'processing', 'on-hold'),
			'limit' => -1,
			'return' => 'ids'
		));

		foreach ($orders as $order_id) {
			$order = wc_get_order($order_id);
			if (!$order)
				continue;

			foreach ($order->get_items() as $item_id => $item) {
				$product_id = $item->get_product_id();
				$product = wc_get_product($product_id);

				// Si es un producto de actividad, marcarlo como válido
				if ($product && $product->get_meta('_is_activity') === 'yes') {
					$valid_bookings[] = $order_id;
					break; // Solo necesitamos saber que el pedido tiene al menos una actividad
				}
			}
		}

		// Eliminar todas las reservas que no corresponden a pedidos válidos
		if (!empty($valid_bookings)) {
			$placeholders = implode(',', array_fill(0, count($valid_bookings), '%d'));

			// Obtener reservas que NO están en la lista de pedidos válidos
			$invalid_bookings = $wpdb->get_results($wpdb->prepare(
				"SELECT id, order_id FROM {$this->table_name} WHERE order_id NOT IN ($placeholders)",
				$valid_bookings
			));
		} else {
			// Si no hay pedidos válidos, todas las reservas son inválidas
			$invalid_bookings = $wpdb->get_results("SELECT id, order_id FROM {$this->table_name}");
		}

		// Eliminar reservas inválidas
		$deleted_count = 0;
		foreach ($invalid_bookings as $booking) {
			// Eliminar fechas propuestas
			$wpdb->delete(
				$this->dates_table_name,
				array('booking_id' => $booking->id),
				array('%d')
			);

			// Eliminar reserva
			$wpdb->delete(
				$this->table_name,
				array('id' => $booking->id),
				array('%d')
			);

			$deleted_count++;
		}

		// Crear reservas faltantes para pedidos válidos
		$created_count = 0;
		foreach ($valid_bookings as $order_id) {
			if (!$this->booking_exists_for_order($order_id)) {
				$this->create_booking_from_order($order_id);
				$created_count++;
			}
		}

		return array(
			'deleted' => $deleted_count,
			'created' => $created_count,
			'total_valid_orders' => count($valid_bookings)
		);
	}

	// Método auxiliar para crear reserva desde un pedido existente
	public function create_booking_from_order($order_id)
	{
		$order = wc_get_order($order_id);
		if (!$order)
			return false;

		foreach ($order->get_items() as $item_id => $item) {
			$product_id = $item->get_product_id();
			$product = wc_get_product($product_id);

			if ($product && $product->get_meta('_is_activity') === 'yes') {
				$collaborator_id = $product->get_meta('_activity_collaborator');

				if (empty($collaborator_id)) {
					continue; // Saltar si no tiene colaborador asignado
				}

				$status = 'pending_dates'; // Valor seguro por defe

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

				$this->create_booking($booking_data);
			}
		}

		return true;
	}

	// Método auxiliar para obtener detalles de reserva desde item del pedido
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

	/**
	 * Obtiene la primera reserva que corresponde a un ID de orden de WooCommerce
	 * @param int $order_id
	 * @return object|null La reserva encontrada, o null.
	 */
	public function get_booking_by_order_id_single($order_id)
	{
		global $wpdb;

		return $wpdb->get_row($wpdb->prepare(
			"SELECT * FROM {$this->table_name} WHERE order_id = %d LIMIT 1",
			$order_id
		));
	}
}
?>