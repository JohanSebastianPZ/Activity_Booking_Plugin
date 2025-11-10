<?php

use PhpMyAdmin\Pdf;

error_reporting(E_ALL & ~E_DEPRECATED);

if (!defined('ABSPATH'))
	exit;

require_once __DIR__ . '/../lib/fpdf.php';

class Booking_invoice_generator extends FPDF
{
	// Constructor
	public function __construct($orientation = 'P', $unit = 'mm', $size = 'A4')
	{
		parent::__construct($orientation, $unit, $size);
	}
	public function init_hooks()
	{
		// Estos son los hooks que necesito para guardar los metadatos del usuario
		add_action('personal_options_update', [$this, 'save_collaborator_fields']); // perfil propio
		add_action('edit_user_profile_update', [$this, 'save_collaborator_fields']); // perfil de otro usuario
	}
	public function save_collaborator_fields($user_id)
	{
		if (!current_user_can('edit_user', $user_id)) {
			return false;
		}

		update_user_meta($user_id, 'empresa_colaborador', sanitize_text_field($_POST['empresa_colaborador'])); // Nombre de la empresa
		update_user_meta($user_id, 'nif_colaborador', sanitize_text_field($_POST['nif_colaborador'])); // NIF de la empresa
		update_user_meta($user_id, 'provincia_colaborador', sanitize_text_field($_POST['provincia_colaborador'])); // Provincia donde esta la empresa
		update_user_meta($user_id, 'ciudad_colaborador', sanitize_text_field($_POST['ciudad_colaborador'])); // Ciudad en donde esta la empresa
	}

	public function generate_invoice($user_id, $booking_obj)
	{

		// --- DATOS DEL COLABORADOR ---

		$enterprise_name = get_user_meta($user_id, 'empresa_colaborador', true);
		$enterprise_nif = get_user_meta($user_id, 'nif_colaborador', true);
		$enterprise_province = get_user_meta($user_id, 'provincia_colaborador', true);
		$enterprise_city = get_user_meta($user_id, 'ciudad_colaborador', true);

		$colaborator_name = get_the_author_meta('display_name', $user_id);
		if (!$colaborator_name) {
			$colaborator_name = "Colaborador ID: " . $user_id;
		}

		error_log("Intentando cargar orden con ID: " . $booking_obj->order_id);

		// 1. OBTENER DATOS DE WOOCOMMERCE
		$order = wc_get_order($booking_obj->order_id);

		if (!$order) {
			$this->AddPage();
			$this->SetFont('Arial', 'B', 16);
			$this->Cell(0, 10, 'ERROR: ORDEN DE WOOCOMMERCE NO ENCONTRADA', 0, 1, 'C');
			$this->Output('I', 'error_factura.pdf');
			exit;
		}

		// Usar datos reales de la orden
		$invoice_number = $order->get_order_number();
		$invoice_date = date_i18n('d/m/Y', $order->get_date_created()->getTimestamp());

		// Inicializar variables para cálculos
		$total_precio_neto = 0;
		$total_comision_bruta = 0;
		$total_honorarios_admin = 0;
		$datos_factura = [];

		// 2. Procesar items de la orden
		$iva_rate = 0.21; // 21% de IVA (Ajustar)
		$commission_rate = 0.15; // 15% de comisión (Ajustar)

		// El campo $booking_obj->booking_details contiene los detalles serializados/JSON.
		$booking_details_raw = $booking_obj->booking_details;
		$booking_details = maybe_unserialize($booking_details_raw);
		$details = json_decode($booking_details, true);
		if (!is_array($details)) {
			$details = [];
		}

		// Usamos 'line_item' para asegurar que solo recorremos productos y no tasas/envío
		foreach ($order->get_items('line_item') as $item_id => $item) {

			$product = $item->get_product();
			$sku = $product ? $product->get_sku() : 'N/A';
			$name = $item->get_name(); // Nombre de la experiencia

			$price_neto_item = $item->get_subtotal(); // Precio neto de la línea de producto
			$comision_item = $price_neto_item * $commission_rate; // Cálculo de comisión

			$meta_data = $item->get_meta_data();
			$ticket_details = [];
			$has_tickets = false;

			foreach ($meta_data as $meta) {
				$data = $meta->get_data();
				$key = $data['key'];
				$value = $data['value'];

				if ($key !== 'Horario' && substr($key, 0, 1) !== '_') {
					$ticket_details[$key] = $value;
					$has_tickets = true;
				}
			}

			if ($has_tickets) {
				// Crear una línea de factura por cada tipo de ticket vendido (Ej: Adulto, Niños)
				foreach ($ticket_details as $type => $quantity) {
					$quantity = intval($quantity);
					if ($quantity <= 0)
						continue;

					// Aquí se crean múltiples líneas si hay varios tipos de tickets
					$datos_factura[] = [
						$name,
						'SKU: ' . $sku,
						$quantity,
						$type, // Tipo (Ej: Adulto)
						number_format($price_neto_item, 2),
						number_format($comision_item, 2)
					];
				}
			} else {
				// Línea de fallback si no hay tickets detallados
				$datos_factura[] = [
					$name,
					'SKU: ' . $sku,
					$item->get_quantity(),
					'General',
					number_format($price_neto_item, 2),
					number_format($comision_item, 2)
				];
			}

			$total_precio_neto += $price_neto_item;
			$total_comision_bruta += $comision_item;
		} // <--- CIERRE DEL FOREACH PRINCIPAL

		// CÁLCULOS FINALES DE COMISIÓN (Base para la factura del colaborador)
		$iva_comision = $total_comision_bruta * $iva_rate;
		$ganancia_neta_final = $total_comision_bruta - $iva_comision - $total_honorarios_admin;


		// --- INICIO DE LA GENERACIÓN DEL PDF (Estructura FPDF) ---

		$this->AddPage();
		$this->SetMargins(10, 20, 10);

		// Cargar fuente DejaVu
		$this->AddFont('dejavusans', '', 'DejaVuSans.php');
		$this->SetFont('dejavusans', '', 10);

		// Logo y encabezado
		$this->Image('https://regalexia.com/wp-content/uploads/2023/03/logo.png', 15, 11, 30, 0, 'PNG');
		$this->Cell(0, 5, utf8_decode('Regalexia'), 0, 1, 'R');
		$this->Cell(0, 5, utf8_decode('www.regalexia.com'), 0, 1, 'R');

		// Información del colaborador
		$this->Ln(20);
		$this->Cell(120, 0, utf8_decode($colaborator_name), 0, 0, 'L');
		$this->Cell(0, 0, utf8_decode("Número de la factura: #" . $invoice_number), 0, 0, 'L'); // DATO REAL

		$this->Ln(6);
		$this->Cell(120, 0, utf8_decode($enterprise_name), 0, 0, 'L');
		$this->Cell(0, 0, utf8_decode("Fecha de la factura: " . $invoice_date), 0, 0, 'L'); // DATO REAL

		$this->Ln(6);
		$this->Cell(120, 0, utf8_decode($enterprise_province . ", " . $enterprise_city), 0, 0, 'L');
		$this->Cell(0, 0, utf8_decode("NIF: " . $enterprise_nif), 0, 0, 'L');

		// Encabezado
		$this->Ln(15);
		$this->SetFillColor(0, 0, 0);
		$this->SetTextColor(255, 255, 255);
		$this->Cell(40, 10, utf8_decode('Experiencia'), 1, 0, 'C', true);
		$this->Cell(40, 10, utf8_decode('Entradas'), 1, 0, 'C', true);
		$this->Cell(40, 10, utf8_decode('Tipo'), 1, 0, 'C', true);
		$this->Cell(35, 10, utf8_decode('Precio Neto'), 1, 0, 'C', true);
		$this->Cell(35, 10, utf8_decode('Comisión Bruta'), 1, 1, 'C', true);

		// Datos de la tabla (DINÁMICA)
		$this->Ln(2);
		$this->SetTextColor(0, 0, 0);
		$this->SetFont('dejavusans', '', 10);

		foreach ($datos_factura as $fila) {

			// Guardamos posición inicial
			$x = $this->GetX();
			$y = $this->GetY();

			// Experiencia + SKU
			$this->SetFont('dejavusans', '', 10);
			$this->MultiCell(40, 6, utf8_decode($fila[0]), 0, 'C'); // Experiencia

			$this->SetFont('dejavusans', '', 8);
			$this->SetTextColor(100, 100, 100);
			$this->SetX($x);
			$this->MultiCell(40, 5, utf8_decode($fila[1]), 0, 'C'); // SKU

			// Recuperamos el alto total ocupado por las 2 líneas
			$alturaCelda = max(10, $this->GetY() - $y);

			// Restauramos color y posición para las demás columnas
			$this->SetTextColor(0, 0, 0);
			$this->SetXY($x + 40, $y);

			$this->SetFont('dejavusans', '', 10);
			$this->Cell(40, $alturaCelda, $fila[2], 0, 0, 'C'); // Entrada (Cantidad)
			$this->Cell(40, $alturaCelda, $fila[3], 0, 0, 'C'); // Tipo
			$this->Cell(35, $alturaCelda, $fila[4] . " " . chr(128), 0, 0, 'C'); // Precio Neto
			$this->Cell(35, $alturaCelda, $fila[5] . " " . chr(128), 0, 1, 'C'); // Comisión Bruta

			// Dejamos una pequeña separación entre filas
			$this->Ln(2);
		}

		// Totales (DINÁMICA)
		$this->Ln(25);

		$totales_reales = [
			"Subtotal" => number_format($total_precio_neto, 2),
			"Impuestor" => number_format(0, 2), // Corregido: Variable $prueba eliminada
			"Total en bruto" => number_format(0, 2),
			"Ganancia bruta" => number_format($total_comision_bruta, 2),
			"I.V.A (" . ($iva_rate * 100) . "%)" => number_format($iva_comision, 2),
			"Ganancias Totales" => number_format($ganancia_neta_final, 2),
			"Honorarios de administración" => number_format($total_honorarios_admin, 2),
		];

		foreach ($totales_reales as $label => $valor) {
			$y = $this->GetY();
			$this->SetLineWidth(0.3);
			$this->Line(120, $y, 200, $y);
			$this->Ln(1);
			$this->Cell(0, 6, utf8_decode("$label: $valor ") . chr(128), 0, 1, 'R');
		}

		// Mostrar el PDF
		$this->Output('I', 'reporte.pdf');
		exit;
	}
}