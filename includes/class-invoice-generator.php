<?php
error_reporting(E_ALL & ~E_DEPRECATED);
// Este comando es para hacer la prueba directamente desde wordpress
//require_once plugin_dir_path(__FILE__) . 'includes/class-invoice-generator.php';

// Este comando es para hacer la prueba local
require_once __DIR__ . '/../lib/fpdf.php';

// Datos de ejemplo
$collaborator_name = "Sebastián";
$collaborator_name_enterprise = "My Life";
$collaborator_location = "Barcelona";
$number_ticket = "#1234";
$date_ticket = "29 Octubre 2025";
$nif_ticket = "2123421";
$subtotal = 0;

// Crear el PDF
$pdf = new FPDF();
$pdf->AddPage();
$pdf->SetMargins(10, 20, 10);

// Cargar fuente DejaVu
$pdf->AddFont('dejavusans', '', 'DejaVuSans.php');
$pdf->SetFont('dejavusans', '', 10);

// Logo y encabezado
$pdf->Image('https://regalexia.com/wp-content/uploads/2023/03/logo.png', 15, 11, 30, 0, 'PNG');
$pdf->Cell(0, 5, utf8_decode('Regalexia'), 0, 1, 'R');
$pdf->Cell(0, 5, utf8_decode('www.regalexia.com'), 0, 1, 'R');

// Información del colaborador
$pdf->Ln(20);
$pdf->Cell(100, 0, utf8_decode($collaborator_name), 0, 0, 'L');
$pdf->Cell(0, 0, utf8_decode("Número de la factura: " . $number_ticket), 0, 0, 'L');

$pdf->Ln(6);
$pdf->Cell(100, 0, utf8_decode($collaborator_name_enterprise), 0, 0, 'L');
$pdf->Cell(0, 0, utf8_decode("Fecha de la factura: " . $date_ticket), 0, 0, 'L');

$pdf->Ln(6);
$pdf->Cell(100, 0, utf8_decode($collaborator_location), 0, 0, 'L');
$pdf->Cell(0, 0, utf8_decode("NIF: " . $nif_ticket), 0, 0, 'L');

// Encabezado negro con texto blanco
$pdf->Ln(15);
$pdf->SetFillColor(0, 0, 0);
$pdf->SetTextColor(255, 255, 255);
$pdf->Cell(40, 10, utf8_decode('Experiencia'), 1, 0, 'C', true);
$pdf->Cell(40, 10, utf8_decode('Entradas'), 1, 0, 'C', true);
$pdf->Cell(40, 10, utf8_decode('Tipo'), 1, 0, 'C', true);
$pdf->Cell(35, 10, utf8_decode('Precio'), 1, 0, 'C', true);
$pdf->Cell(35, 10, utf8_decode('Comisión'), 1, 1, 'C', true);

// Datos de la tabla
$pdf->Ln(2);
$pdf->SetTextColor(0, 0, 0);
$pdf->SetFont('dejavusans', '', 10);

// Orden
// Nombre_Experiencia - SKU - Cantidad_Entradas - Tipo - Precio - Comision
$datos = [
    ['Salto en Paracaidas', 'SKU: 114-FLU-PF', 2, "Individual", '122', '1.43'],
    ['Experiencia 2', 'SKU: 115-FLU-PR', 1, "Familiar", '15', '1.5'],
    ['Experiencia 3', 'SKU: 116-FLU-GR', 3, "Individual", '30', '3']
];

foreach ($datos as $fila) {

    // Guardamos posición inicial
    $x = $pdf->GetX();
    $y = $pdf->GetY();

    // Experiencia + SKU
    $pdf->SetFont('dejavusans', '', 10);
    $pdf->MultiCell(40, 6, utf8_decode($fila[0]), 0, 'C'); // Experiencia

    $pdf->SetFont('dejavusans', '', 8);
    $pdf->SetTextColor(100, 100, 100);
    $pdf->SetX($x);
    $pdf->MultiCell(40, 5, utf8_decode($fila[1]), 0, 'C'); // SKU

    // Recuperamos el alto total ocupado por las 2 líneas
    $alturaCelda = max(10, $pdf->GetY() - $y);

    // Restauramos color y posición para las demás columnas
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetXY($x + 40, $y);

    // Resto de columnas
    $pdf->SetFont('dejavusans', '', 10);
    $pdf->Cell(40, $alturaCelda, $fila[2], 0, 0, 'C'); // Entrada
    $pdf->Cell(40, $alturaCelda, $fila[3], 0, 0, 'C'); // Tipo
    $pdf->Cell(35, $alturaCelda, $fila[4] . " " . chr(128), 0, 0, 'C'); // Precio
    $pdf->Cell(35, $alturaCelda, $fila[5] . " " . chr(128), 0, 1, 'C'); // Comision

    // Dejamos una pequeña separación entre filas
    $pdf->Ln(2);
}

// Totales
$pdf->Ln(15);
$totales = [
    "Subtotal" => $subtotal,
    "Impuesto" => $subtotal,
    "Total en bruto" => $subtotal,
    "Ganancia bruta" => $subtotal,
    "I.V.A (21%)" => $subtotal,
    "Ganancias Totales" => $subtotal,
    "Honorarios de administración" => $subtotal
];

foreach ($totales as $label => $valor) {
    $y = $pdf->GetY();
    $pdf->SetLineWidth(0.3);
    $pdf->Line(120, $y, 200, $y);
    $pdf->Ln(2);
    $pdf->Cell(0, 6, utf8_decode("$label: $valor ").chr(128), 0, 1, 'R');
}

// Mostrar el PDF
$pdf->Output('I', 'reporte.pdf');
exit;