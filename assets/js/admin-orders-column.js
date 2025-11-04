console.log("admin-orders-column.js cargado correctamente (modo clásico detectado)");

function waitForClassicOrdersTable() {
    const table = document.querySelector('.wp-list-table.wc-orders-list-table');
    const header = table?.querySelector('thead tr');
    const body = table?.querySelector('tbody');

    if (!table || !header || !body) {
        console.log("⏳ Esperando a que se renderice la tabla clásica...");
        setTimeout(waitForClassicOrdersTable, 500);
        return;
    }

    if (document.querySelector('.factura-col-header')) {
        console.log("Columna 'Factura' ya existe, deteniendo.");
        return;
    }

    console.log("Tabla detectada, añadiendo columna visual 'Factura'...");

    // Crear encabezado
    const th = document.createElement('th');
    th.className = 'factura-col-header';
    th.innerText = 'Factura';
    th.style.textAlign = 'center';
    header.appendChild(th);

    // Crear columna por fila
    body.querySelectorAll('tr').forEach((row) => {
        const td = document.createElement('td');
        td.className = 'factura-col-cell';
        td.style.textAlign = 'center';
        td.innerHTML = '<button class="button">Descargar</button>';
        row.appendChild(td);
    });

    // Observar cambios en la tabla (paginación, búsqueda, etc.)
    const observer = new MutationObserver(() => {
        body.querySelectorAll('tr').forEach((row) => {
            if (!row.querySelector('.factura-col-cell')) {
                const td = document.createElement('td');
                td.className = 'factura-col-cell';
                td.style.textAlign = 'center';
                td.innerHTML = '<button class="button">Descargar</button>';
                row.appendChild(td);
            }
        });
    });

    observer.observe(body, { childList: true, subtree: false });
}

document.addEventListener('DOMContentLoaded', waitForClassicOrdersTable);
