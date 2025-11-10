console.log("admin-orders-column.js cargado correctamente. Descarga de factura habilitada.");

/**
 * Función para esperar y modificar la tabla de pedidos de WooCommerce (modo clásico/no-HPOS)
 */
function waitForClassicOrdersTable() {
	const table = document.querySelector(".wp-list-table.wc-orders-list-table");
	const header = table?.querySelector("thead tr");
	const body = table?.querySelector("tbody");

	if (!table || !header || !body) {
		setTimeout(waitForClassicOrdersTable, 500);
		return;
	}

	// Si la columna ya existe, evitamos duplicarla
	if (document.querySelector(".factura-col-header")) {
		console.log("Columna 'Factura' ya existe.");
		return;
	}

	console.log("Tabla de pedidos detectada, añadiendo columna 'Factura'...");

	// 1. Crear encabezado de la columna 'Factura'
	const th = document.createElement("th");
	th.className = "factura-col-header";
	th.innerText = "Factura";
	th.style.textAlign = "center";
	header.appendChild(th);

	/**
	 * Función auxiliar para añadir el botón a una fila
	 */
	const addButtonToRow = (row) => {
		// Obtenemos el ID de la orden. En el modo clásico, el ID está en el atributo 'id="post-ID"'
		const rowId = row.id;
		let orderId = 0;

		// Extraer el número de la orden del ID (ej: post-123 -> 123)

		if (rowId && rowId.startsWith("post-")) {
			orderId = rowId.replace("post-", "");
		} else if (rowId && rowId.startsWith("order-")) {
			orderId = rowId.replace("order-", "");
		} else if (row.dataset.id) {
			// Fallback por si usan data-id
			orderId = row.dataset.id;
		}

		// Si la celda de la factura aún no existe, la creamos
		if (!row.querySelector(".factura-col-cell")) {
			const td = document.createElement("td");
			td.className = "factura-col-cell";
			td.style.textAlign = "center";

			// Inyectamos el botón con el data-booking-id (que es el ID de la ORDEN)
			if (orderId && orderId > 0) {
				td.innerHTML = `<button class="button btn-download-admin-invoice" data-booking-id="${orderId}">Descargar</button>`;
			} else {
				td.innerHTML = `<span style="color:red;">ID no encontrado</span>`;
			}

			row.appendChild(td);
		}
	};

	// 2. Añadir el botón a todas las filas existentes
	body.querySelectorAll("tr").forEach(addButtonToRow);

	// 3. Observar cambios en la tabla (para paginación o filtros AJAX)
	const observer = new MutationObserver((mutations) => {
		mutations.forEach((mutation) => {
			if (mutation.type === "childList") {
				mutation.addedNodes.forEach((node) => {
					// Solo procesamos si es un elemento TR (una fila)
					if (node.tagName === "TR") {
						addButtonToRow(node);
					}
				});
			}
		});
	});

	observer.observe(body, { childList: true, subtree: false });
}

// MANEJO DE EVENTOS (AJAX)

jQuery(document).ready(function ($) {
	// Usamos la delegación de eventos 'on' para asegurarnos de que el clic funcione tanto en los botones cargados inicialmente como en los añadidos por AJAX (observador).
	$(document).on("click", ".btn-download-admin-invoice", function (e) {
		e.preventDefault();

		// Obtenemos el ID de la orden inyectado en el botón
		const bookingId = $(this).data("booking-id");

		if (!bookingId || bookingId === 0) {
			alert("No se encontró el ID de la reserva (Order ID).");
			return;
		}

		// Chequeo de seguridad: Confirmamos que la variable ab_ajax existe
		if (typeof ab_ajax === "undefined" || !ab_ajax.nonce) {
			alert("Error de configuración: La variable de seguridad (ab_ajax) no está definida.");
			console.error("Referencia faltante: ab_ajax.nonce no está definido.");
			return;
		}

		// Generamos la URL para la acción AJAX (generate_invoice)
		const url = ab_ajax.url + "?action=generate_invoice" + "&booking_id=" + bookingId + "&_wpnonce=" + ab_ajax.nonce;

		console.log("Descargando factura para la orden " + bookingId + " desde:", url);

		// Abrimos el PDF en una nueva pestaña (necesario para FPDF)
		window.open(url, "_blank");
	});

	// Iniciar el proceso de detección de la tabla una vez que el DOM esté listo
	waitForClassicOrdersTable();
});
