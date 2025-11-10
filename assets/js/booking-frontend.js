jQuery(document).ready(function($) {
    console.log("booking-frontend.js cargado correctamente ✅");

    $('.btn-download-invoice').on('click', function(e) {
        e.preventDefault();

        const bookingId = $(this).data('booking-id');

        if (!bookingId) {
            alert('No se encontró el ID de la reserva.');
            return;
        }

        // Generamos la URL para la acción AJAX
        const url = ab_ajax.url + '?action=generate_invoice&booking_id=' + bookingId + '&_wpnonce=' + ab_ajax.nonce;

        console.log("Descargando factura desde:", url);

        // Abrir el PDF en una nueva pestaña
        window.open(url, '_blank');
    });
});
